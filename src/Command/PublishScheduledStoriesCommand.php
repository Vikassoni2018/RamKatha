<?php

declare(strict_types=1);

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'app:publish-scheduled-stories', description: 'Publishes stories whose scheduled time has passed (idempotent; run every minute).')]
final class PublishScheduledStoriesCommand extends AbstractPeriodicTaskCommand
{
    protected function taskName(): string
    {
        return 'publish-scheduled';
    }
}
