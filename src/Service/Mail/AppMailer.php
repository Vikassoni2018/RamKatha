<?php

declare(strict_types=1);

namespace App\Service\Mail;

use App\Exception\FeatureNotConfiguredException;
use App\Service\Config\FeatureFlags;
use App\Service\Settings\SettingsRegistry;
use App\Service\Settings\SettingsService;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;

/**
 * Sends HTML + plain-text emails. The application boots without mail configuration; callers get a
 * FeatureNotConfiguredException (shown as a clear message) instead of a silent no-op.
 */
final class AppMailer
{
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly FeatureFlags $features,
        private readonly SettingsService $settings,
        #[Autowire(env: 'MAILER_FROM')]
        private readonly string $fromAddress,
        #[Autowire(env: 'MAILER_FROM_NAME')]
        private readonly string $fromName,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function isConfigured(): bool
    {
        return $this->features->mailerConfigured();
    }

    /**
     * @param array<string, mixed> $context
     *
     * @throws FeatureNotConfiguredException
     * @throws MailDeliveryException
     */
    public function send(string $to, string $subject, string $template, array $context = [], ?string $replyTo = null): void
    {
        if (!$this->isConfigured()) {
            throw new FeatureNotConfiguredException('mail', 'Email is not configured on this server (MAILER_DSN). Please contact the site administrator.');
        }

        $siteName = $this->settings->getString(SettingsRegistry::SITE_NAME);
        $email = (new TemplatedEmail())
            ->from(new Address($this->fromAddress, $this->fromName ?: $siteName))
            ->to($to)
            ->subject($subject)
            ->htmlTemplate('email/'.$template.'.html.twig')
            ->textTemplate('email/'.$template.'.txt.twig')
            ->context($context + ['site_name' => $siteName, 'subject' => $subject]);
        if (null !== $replyTo) {
            $email->replyTo($replyTo);
        }

        try {
            $this->mailer->send($email);
        } catch (TransportExceptionInterface $e) {
            $this->logger->error('Mail delivery failed: {error}', ['error' => $e->getMessage()]);
            throw new MailDeliveryException('The email could not be sent. Please try again later.', previous: $e);
        }
    }
}
