<?php

declare(strict_types=1);

namespace App\Service\System;

use App\Repository\AccountTokenRepository;
use App\Repository\AdminNotificationRepository;
use App\Repository\DonationRepository;
use App\Service\Storage\TempDirectoryManager;
use Symfony\Component\Clock\ClockInterface;

/** Daily housekeeping: temp files, expired tokens, stale "initiated" donations, old notifications. */
final class MaintenanceService
{
    public function __construct(
        private readonly TempDirectoryManager $temp,
        private readonly AccountTokenRepository $tokens,
        private readonly DonationRepository $donations,
        private readonly AdminNotificationRepository $notifications,
        private readonly ClockInterface $clock,
    ) {
    }

    public function run(): string
    {
        $now = $this->clock->now();
        $temp = $this->temp->purgeOlderThan(6 * 3600);
        $tokens = $this->tokens->deleteExpired($now);
        // "initiated" means only that a QR was displayed: no payment claim, safe to purge after 30 days.
        $initiated = $this->donations->deleteStaleInitiated($now->modify('-30 days'));
        $notifications = $this->notifications->deleteReadBefore($now->modify('-90 days'));

        return \sprintf('temp dirs: %d, expired tokens: %d, stale initiated donations: %d, old notifications: %d', $temp, $tokens, $initiated, $notifications);
    }
}
