<?php

declare(strict_types=1);

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'app:audio:retry-failed', description: 'Re-queues failed or abandoned narration jobs (bounded attempts).')]
final class AudioRetryFailedCommand extends AbstractPeriodicTaskCommand
{
    protected function taskName(): string
    {
        return 'audio-retry';
    }
}
