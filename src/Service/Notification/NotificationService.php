<?php

declare(strict_types=1);

namespace App\Service\Notification;

use App\Entity\AdminNotification;
use App\Enum\NotificationType;
use App\Message\SendAdminNotificationEmail;
use App\Service\Settings\SettingsRegistry;
use App\Service\Settings\SettingsService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Internal staff notifications. Failures may additionally be emailed (asynchronously) to the
 * configured contact address. Deliberately minimal: no user-facing notification spam.
 */
final class NotificationService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MessageBusInterface $bus,
        private readonly SettingsService $settings,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function notify(NotificationType $type, string $title, ?string $message = null, ?string $link = null): AdminNotification
    {
        $notification = new AdminNotification($type, mb_substr($title, 0, 255), $message, $link);
        $this->em->persist($notification);
        $this->em->flush();

        if ($type->shouldEmail()
            && $this->settings->getBool(SettingsRegistry::NOTIFY_EMAIL_FAILURES)
            && '' !== $this->settings->getString(SettingsRegistry::CONTACT_EMAIL)) {
            try {
                $this->bus->dispatch(new SendAdminNotificationEmail((int) $notification->getId()));
            } catch (\Throwable $e) {
                // Never let a notification failure break the calling workflow.
                $this->logger->warning('Could not queue notification email: {error}', ['error' => $e->getMessage()]);
            }
        }

        return $notification;
    }
}
