<?php

declare(strict_types=1);

namespace App\Service\Donation;

use App\DTO\DonationDeclaration;
use App\Entity\Donation;
use App\Entity\Story;
use App\Entity\User;
use App\Enum\AuditAction;
use App\Enum\DonationStatus;
use App\Enum\MetricEvent;
use App\Enum\NotificationType;
use App\Exception\DomainRuleException;
use App\Exception\FeatureNotConfiguredException;
use App\Repository\DonationRepository;
use App\Service\Analytics\AnalyticsRecorder;
use App\Service\Audit\AuditLogger;
use App\Service\Notification\NotificationService;
use App\Service\Settings\SettingsRegistry;
use App\Service\Settings\SettingsService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Manual UPI contributions (Mode A):
 *   initiate (UPI intent/QR shown) → declare (donor reports reference) → verify/reject (admin).
 * A donation is NEVER marked verified automatically: only an admin (or a future server-side
 * verified gateway) can do so. All status changes are transactional and audited.
 *
 * Messages of exceptions the donor can see (initiate/declare) are translated into the language of
 * the current page; admin-only messages stay English.
 */
final class DonationService
{
    private const REFERENCE_ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly DonationRepository $donations,
        private readonly UpiConfiguration $upi,
        private readonly UpiPaymentUriBuilder $uriBuilder,
        private readonly MoneyFormatter $money,
        private readonly SettingsService $settings,
        private readonly AnalyticsRecorder $analytics,
        private readonly AuditLogger $audit,
        private readonly NotificationService $notifications,
        private readonly ClockInterface $clock,
        #[Autowire(service: 'monolog.logger.donation')]
        private readonly LoggerInterface $logger,
        private readonly TranslatorInterface $translator,
    ) {
    }

    /** @throws FeatureNotConfiguredException */
    public function assertAvailable(): void
    {
        if (!$this->settings->getBool(SettingsRegistry::DONATIONS_ENABLED)) {
            throw new FeatureNotConfiguredException('donations', $this->translator->trans('donation.error.unavailable'));
        }
        if (null !== $error = $this->upi->getConfigurationError()) {
            throw new FeatureNotConfiguredException('upi', 'Feature is not configured: '.$error);
        }
    }

    /**
     * @throws FeatureNotConfiguredException|DomainRuleException
     */
    public function initiate(int $amountPaise, ?Story $story, ?User $user): Donation
    {
        $this->assertAvailable();
        $this->assertAmountAllowed($amountPaise);

        $reference = $this->generateReferenceCode();
        $donation = new Donation($amountPaise, $reference, $this->uriBuilder->sanitizeNote('Seva '.$reference), $this->upi->getCurrency());
        $donation->setStory($story);
        $donation->setUser($user);
        if (null !== $user) {
            $donation->setDonorName($user->getDisplayName());
        }
        $this->em->persist($donation);
        $this->em->flush();

        if (null !== $story) {
            $this->analytics->record((int) $story->getId(), MetricEvent::DonationInitiated);
        }
        $this->logger->info('Donation {ref} initiated: {amount}', ['ref' => $reference, 'amount' => $amountPaise]);

        return $donation;
    }

    public function upiUri(Donation $donation): string
    {
        return $this->uriBuilder->build(
            $this->upi->getUpiId(),
            $this->upi->getPayeeName(),
            $donation->getAmountPaise(),
            $donation->getPaymentNote(),
            $donation->getCurrency(),
        );
    }

    /**
     * Donor-reported payment details. Changes nothing financially: it only queues the donation
     * for admin verification.
     */
    public function declare(Donation $donation, DonationDeclaration $declaration): void
    {
        if (!\in_array($donation->getStatus(), [DonationStatus::Initiated, DonationStatus::Declared], true)) {
            throw new DomainRuleException($this->translator->trans('donation.error.locked'));
        }
        $wasInitiated = DonationStatus::Initiated === $donation->getStatus();

        if (null !== $declaration->amount && '' !== $declaration->amount) {
            $paise = $this->money->parseToPaise($declaration->amount);
            if (null === $paise) {
                throw new DomainRuleException($this->translator->trans('donation.error.invalid_amount'));
            }
            $this->assertAmountAllowed($paise);
            $donation->setAmountPaise($paise);
        }

        $reference = null !== $declaration->transactionReference ? strtoupper(trim($declaration->transactionReference)) : null;
        $donation->setUpiTransactionReference('' !== $reference ? $reference : null);
        $donation->setDonorName($this->clean($declaration->payerName, 120) ?? $donation->getDonorName());
        $donation->setEmail($this->clean($declaration->email, 180));
        $donation->setPhone($this->clean($declaration->phone, 20));
        $donation->setNotes($this->clean($declaration->message, 1000));
        $donation->setPaymentDate($declaration->paymentDate);
        $donation->setAnonymous($declaration->anonymous);
        $donation->setDeclaredAt($this->clock->now());
        $donation->setStatus(DonationStatus::Declared);

        if (null !== $reference && $this->donations->countWithReference($reference, $donation->getId()) > 0) {
            $donation->setAdminNotes(trim(($donation->getAdminNotes() ?? '')."\n⚠ The same transaction reference was declared on another donation."));
        }
        $this->em->flush();

        if ($wasInitiated) {
            if (null !== $donation->getStory()) {
                $this->analytics->record((int) $donation->getStory()->getId(), MetricEvent::DonationDeclared);
            }
            $this->notifications->notify(
                NotificationType::DonationDeclared,
                \sprintf('Contribution declared: %s (%s)', $this->money->format($donation->getAmountPaise()), $donation->getReferenceCode()),
                'Awaiting manual verification against the bank statement.',
                '/admin/donations/'.$donation->getId(),
            );
        }
        $this->logger->info('Donation {ref} declared (UTR present: {utr})', ['ref' => $donation->getReferenceCode(), 'utr' => null !== $reference ? 'yes' : 'no']);
    }

    public function verify(Donation $donation, User $admin, ?string $note = null): void
    {
        $this->transition($donation, DonationStatus::Verified, $admin, $note, function (Donation $d, \DateTimeImmutable $now) use ($admin): void {
            $d->markVerified($admin, $now);
            if (null !== $d->getStory()) {
                $storyId = (int) $d->getStory()->getId();
                $this->analytics->recordColumn($storyId, 'donations_verified');
                $this->analytics->recordColumn($storyId, 'donation_amount_paise', $d->getAmountPaise());
            }
        }, AuditAction::DonationVerified);
    }

    public function reject(Donation $donation, User $admin, ?string $reason = null): void
    {
        $this->transition($donation, DonationStatus::Rejected, $admin, $reason, static function (Donation $d, \DateTimeImmutable $now) use ($admin): void {
            $d->markRejected($admin, $now);
        }, AuditAction::DonationRejected);
    }

    public function updateAdminNotes(Donation $donation, ?string $notes): void
    {
        $donation->setAdminNotes($this->clean($notes, 5000));
        $this->audit->log(AuditAction::DonationUpdated, $donation, ['field' => 'adminNotes']);
        $this->em->flush();
    }

    /** @param callable(Donation, \DateTimeImmutable): void $apply */
    private function transition(Donation $donation, DonationStatus $to, User $admin, ?string $note, callable $apply, AuditAction $action): void
    {
        $this->em->wrapInTransaction(function () use ($donation, $to, $admin, $note, $apply, $action): void {
            // Lock the row so two admins cannot decide the same donation concurrently.
            $this->em->refresh($donation, \Doctrine\DBAL\LockMode::PESSIMISTIC_WRITE);
            $from = $donation->getStatus();
            if (!$from->canTransitionTo($to)) {
                throw new DomainRuleException(\sprintf('A donation cannot go from "%s" to "%s".', $from->label(), $to->label()));
            }
            $now = $this->clock->now();
            $donation->setStatus($to);
            $apply($donation, $now);
            if (null !== $note && '' !== trim($note)) {
                $donation->setAdminNotes(trim(($donation->getAdminNotes() ?? '')."\n[".$now->format('Y-m-d H:i').' UTC, '.$admin->getDisplayName().'] '.trim($note)));
            }
            $this->audit->log($action, $donation, [
                'from' => $from->value,
                'to' => $to->value,
                'reference' => $donation->getReferenceCode(),
                'amountPaise' => $donation->getAmountPaise(),
                'note' => $note,
            ], actor: $admin);
            $this->em->flush();
        });
        $this->logger->info('Donation {ref} set to {to} by admin #{admin}', ['ref' => $donation->getReferenceCode(), 'to' => $to->value, 'admin' => $admin->getId()]);
    }

    public function assertAmountAllowed(int $amountPaise): void
    {
        $min = $this->settings->getInt(SettingsRegistry::DONATION_MIN) * 100;
        $max = $this->settings->getInt(SettingsRegistry::DONATION_MAX) * 100;
        if ($amountPaise < $min || $amountPaise > $max) {
            throw new DomainRuleException($this->translator->trans('donation.error.amount_range', [
                '%min%' => $this->money->format($min),
                '%max%' => $this->money->format($max),
            ]));
        }
    }

    /** @return list<int> preset amounts in rupees */
    public function presetAmounts(): array
    {
        return $this->settings->getIntList(SettingsRegistry::DONATION_PRESETS);
    }

    private function generateReferenceCode(): string
    {
        $alphabetLength = \strlen(self::REFERENCE_ALPHABET);
        do {
            $code = 'K';
            for ($i = 0; $i < 7; ++$i) {
                $code .= self::REFERENCE_ALPHABET[random_int(0, $alphabetLength - 1)];
            }
        } while ($this->donations->referenceCodeExists($code));

        return $code;
    }

    private function clean(?string $value, int $max): ?string
    {
        if (null === $value) {
            return null;
        }
        $value = trim(strip_tags($value));

        return '' === $value ? null : mb_substr($value, 0, $max);
    }
}
