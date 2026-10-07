<?php

declare(strict_types=1);

namespace App\Service\Account;

use App\Entity\User;
use App\Enum\AccountTokenType;
use App\Enum\AuditAction;
use App\Exception\FeatureNotConfiguredException;
use App\Repository\UserRepository;
use App\Service\Audit\AuditLogger;
use App\Service\Mail\AppMailer;
use App\Service\Mail\MailDeliveryException;
use App\Service\Seo\SiteUrlGenerator;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Account lifecycle: registration, email verification, password reset/change, deactivation.
 */
final class AccountService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserRepository $users,
        private readonly UserPasswordHasherInterface $hasher,
        private readonly AccountTokenService $tokens,
        private readonly AppMailer $mailer,
        private readonly SiteUrlGenerator $urls,
        private readonly AuditLogger $audit,
        private readonly TranslatorInterface $translator,
        #[Autowire(service: 'monolog.logger.security')]
        private readonly LoggerInterface $securityLogger,
    ) {
    }

    public function register(User $user, string $plainPassword): void
    {
        $user->setPassword($this->hasher->hashPassword($user, $plainPassword));
        $this->em->persist($user);
        $this->em->flush();
        $this->securityLogger->info('New account registered: #{id}', ['id' => $user->getId()]);
    }

    /**
     * Sends (or re-sends) the verification email. It is sent during the request, so its subject,
     * text and link follow the language of the page the visitor is using.
     *
     * @throws FeatureNotConfiguredException|MailDeliveryException
     */
    public function sendVerificationEmail(User $user): void
    {
        if ($user->isVerified()) {
            return;
        }
        $token = $this->tokens->issue($user, AccountTokenType::EmailVerification);
        $this->mailer->send($user->getEmail(), $this->translator->trans('email.verify.subject'), 'verify_email', [
            'user' => $user,
            'url' => $this->urls->route('app_verify_email', ['token' => $token]),
            'hours' => intdiv(AccountTokenType::EmailVerification->ttl(), 3600),
        ]);
    }

    public function verifyEmail(string $token): ?User
    {
        $user = $this->tokens->consume($token, AccountTokenType::EmailVerification);
        if (null !== $user) {
            $user->setIsVerified(true);
            $this->em->flush();
        }

        return $user;
    }

    /**
     * Never reveals whether an account exists: unknown emails silently do nothing.
     *
     * @throws FeatureNotConfiguredException|MailDeliveryException
     */
    public function requestPasswordReset(string $email): void
    {
        if (!$this->mailer->isConfigured()) {
            throw new FeatureNotConfiguredException('mail', 'Password reset emails cannot be sent because email is not configured on this server. Please contact the site administrator.');
        }
        $user = $this->users->findOneByEmail($email);
        if (null === $user || !$user->isActive()) {
            $this->securityLogger->info('Password reset requested for unknown/inactive email.');

            return;
        }
        $token = $this->tokens->issue($user, AccountTokenType::PasswordReset);
        $this->mailer->send($user->getEmail(), $this->translator->trans('email.reset_password.subject'), 'reset_password', [
            'user' => $user,
            'url' => $this->urls->route('app_reset_password', ['token' => $token]),
            'minutes' => intdiv(AccountTokenType::PasswordReset->ttl(), 60),
        ]);
        $this->securityLogger->info('Password reset email sent to user #{id}', ['id' => $user->getId()]);
    }

    public function findUserForResetToken(string $token): ?User
    {
        return $this->tokens->peek($token, AccountTokenType::PasswordReset);
    }

    public function resetPassword(string $token, string $plainPassword): ?User
    {
        $user = $this->tokens->consume($token, AccountTokenType::PasswordReset);
        if (null === $user) {
            return null;
        }
        $this->changePassword($user, $plainPassword);
        // A successful reset proves ownership of the mailbox.
        $user->setIsVerified(true);
        $this->em->flush();

        return $user;
    }

    public function changePassword(User $user, string $plainPassword): void
    {
        $user->setPassword($this->hasher->hashPassword($user, $plainPassword));
        $this->em->flush();
        $this->securityLogger->info('Password changed for user #{id}', ['id' => $user->getId()]);
    }

    public function isPasswordValid(User $user, string $plainPassword): bool
    {
        return $this->hasher->isPasswordValid($user, $plainPassword);
    }

    /**
     * Self-service deactivation. Personal data that is not needed for financial/audit records is
     * removed: collections, bookmarks and progress are deleted; donations keep only what accounting needs.
     */
    public function deactivateOwnAccount(User $user): void
    {
        $this->em->wrapInTransaction(function () use ($user): void {
            $conn = $this->em->getConnection();
            $conn->executeStatement('DELETE FROM user_collection WHERE user_id = ?', [$user->getId()]);
            $conn->executeStatement('DELETE FROM bookmark WHERE user_id = ?', [$user->getId()]);
            $conn->executeStatement('DELETE FROM story_progress WHERE user_id = ?', [$user->getId()]);
            $conn->executeStatement('DELETE FROM account_token WHERE user_id = ?', [$user->getId()]);

            $user->deactivate();
            $user->setDisplayName('Deleted user');
            $user->setEmail(\sprintf('deleted-%s@invalid.local', $user->getUuid()->toBase58()));
            $user->setPassword('!'.bin2hex(random_bytes(16)));
            $user->setRoles([]);
            $this->audit->log(AuditAction::UserDeactivated, $user, ['self' => true], actor: null);
            $this->em->flush();
        });
        $this->securityLogger->info('User #{id} deactivated their account.', ['id' => $user->getId()]);
    }
}
