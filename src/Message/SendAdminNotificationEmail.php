<?php

declare(strict_types=1);

namespace App\Message;

/** Emails a failure notification to the configured contact address. */
final class SendAdminNotificationEmail
{
    public function __construct(
        public readonly int $notificationId,
    ) {
    }
}
