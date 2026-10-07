<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Exception\FeatureNotConfiguredException;
use App\Message\SendAdminNotificationEmail;
use App\Repository\AdminNotificationRepository;
use App\Service\Mail\AppMailer;
use App\Service\Seo\SiteUrlGenerator;
use App\Service\Settings\SettingsRegistry;
use App\Service\Settings\SettingsService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class SendAdminNotificationEmailHandler
{
    public function __construct(
        private readonly AdminNotificationRepository $notifications,
        private readonly AppMailer $mailer,
        private readonly SettingsService $settings,
        private readonly SiteUrlGenerator $urls,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(SendAdminNotificationEmail $message): void
    {
        $notification = $this->notifications->find($message->notificationId);
        $to = $this->settings->getString(SettingsRegistry::CONTACT_EMAIL);
        if (null === $notification || '' === $to) {
            return;
        }
        try {
            $this->mailer->send($to, '[Admin] '.$notification->getTitle(), 'admin_notification', [
                'notification' => $notification,
                'url' => null !== $notification->getLink() ? $this->urls->absolute($notification->getLink()) : null,
            ]);
        } catch (FeatureNotConfiguredException $e) {
            // Mail not configured: the notification is still visible in the admin panel.
            $this->logger->info('Notification email skipped: {reason}', ['reason' => $e->getMessage()]);
        }
    }
}
