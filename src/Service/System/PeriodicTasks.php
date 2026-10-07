<?php

declare(strict_types=1);

namespace App\Service\System;

use App\Service\Analytics\AnalyticsAggregator;
use App\Service\Audio\AudioRetryService;
use App\Service\Social\SocialPostService;
use App\Service\Story\ScheduledPublisher;

/**
 * The recurring jobs, each guarded by a lock and recorded as a heartbeat. Shared by the cron
 * fallback (app:cron:run and the individual commands) and the Symfony Scheduler worker, so both
 * execution modes behave identically and can never overlap.
 */
final class PeriodicTasks
{
    /** name => minimum minutes between runs when driven by app:cron:run */
    public const INTERVALS = [
        'publish-scheduled' => 1,
        'social-due' => 5,
        'audio-retry' => 10,
        'analytics' => 60,
        'maintenance' => 1440,
    ];

    public function __construct(
        private readonly JobRunner $runner,
        private readonly ScheduledPublisher $scheduledPublisher,
        private readonly SocialPostService $social,
        private readonly AudioRetryService $audioRetry,
        private readonly AnalyticsAggregator $analytics,
        private readonly MaintenanceService $maintenance,
    ) {
    }

    /** @return array{status: string, message: ?string} */
    public function run(string $name): array
    {
        return match ($name) {
            'publish-scheduled' => $this->runner->run($name, function (): string {
                $titles = $this->scheduledPublisher->publishDue();

                return [] === $titles ? 'nothing due' : 'published: '.implode(', ', $titles);
            }, 300),
            'social-due' => $this->runner->run($name, fn (): string => 'processed '.$this->social->processDue().' post(s)', 600),
            'audio-retry' => $this->runner->run($name, fn (): string => 're-queued '.$this->audioRetry->retryAutomatically().' job(s)', 600),
            'analytics' => $this->runner->run($name, fn (): string => json_encode($this->analytics->aggregate(), \JSON_THROW_ON_ERROR), 1800),
            'maintenance' => $this->runner->run($name, fn (): string => $this->maintenance->run(), 1800),
            default => throw new \InvalidArgumentException('Unknown periodic task '.$name),
        };
    }
}
