<?php

declare(strict_types=1);

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'app:social:publish-pending', description: 'Publishes due social posts and retries transient Facebook failures.')]
final class SocialPublishPendingCommand extends AbstractPeriodicTaskCommand
{
    protected function taskName(): string
    {
        return 'social-due';
    }
}
