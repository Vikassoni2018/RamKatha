<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\AggregateAnalytics;
use App\Message\PublishDueSocialPosts;
use App\Message\PublishScheduledStories;
use App\Message\RetryFailedAudio;
use App\Message\RunMaintenance;
use App\Service\System\PeriodicTasks;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/** Handles the Scheduler's recurring trigger messages by delegating to PeriodicTasks. */
final class PeriodicTaskHandler
{
    public function __construct(private readonly PeriodicTasks $tasks)
    {
    }

    #[AsMessageHandler]
    public function publishScheduled(PublishScheduledStories $message): void
    {
        $this->tasks->run('publish-scheduled');
    }

    #[AsMessageHandler]
    public function socialDue(PublishDueSocialPosts $message): void
    {
        $this->tasks->run('social-due');
    }

    #[AsMessageHandler]
    public function audioRetry(RetryFailedAudio $message): void
    {
        $this->tasks->run('audio-retry');
    }

    #[AsMessageHandler]
    public function analytics(AggregateAnalytics $message): void
    {
        $this->tasks->run('analytics');
    }

    #[AsMessageHandler]
    public function maintenance(RunMaintenance $message): void
    {
        $this->tasks->run('maintenance');
    }
}
