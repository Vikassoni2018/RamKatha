<?php

declare(strict_types=1);

namespace App\Scheduler;

use App\Message\AggregateAnalytics;
use App\Message\PublishDueSocialPosts;
use App\Message\PublishScheduledStories;
use App\Message\RetryFailedAudio;
use App\Message\RunMaintenance;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Scheduler\Attribute\AsSchedule;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;
use Symfony\Contracts\Cache\CacheInterface;

/**
 * Optional persistent-worker mode:  php bin/console messenger:consume scheduler_default async
 *
 * On hosting without long-running workers use the cron fallback instead (`app:cron:run`, see
 * README). Both paths run the same PeriodicTasks under the same locks, so enabling both is safe.
 */
#[AsSchedule('default')]
final class MainSchedule implements ScheduleProviderInterface
{
    public function __construct(
        #[Autowire(service: 'cache.app')]
        private readonly CacheInterface $cache,
    ) {
    }

    public function getSchedule(): Schedule
    {
        return (new Schedule())
            ->add(
                RecurringMessage::every('1 minute', new PublishScheduledStories()),
                RecurringMessage::every('5 minutes', new PublishDueSocialPosts()),
                RecurringMessage::every('10 minutes', new RetryFailedAudio()),
                RecurringMessage::every('1 hour', new AggregateAnalytics()),
                RecurringMessage::every('1 day', new RunMaintenance(), from: new \DateTimeImmutable('03:15', new \DateTimeZone('UTC'))),
            )
            ->stateful($this->cache)
            ->processOnlyLastMissedRun(true);
    }
}
