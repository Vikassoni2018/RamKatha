<?php

declare(strict_types=1);

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'app:maintenance', description: 'Daily housekeeping: temp files, expired tokens, stale initiated contributions, old notifications.')]
final class MaintenanceCommand extends AbstractPeriodicTaskCommand
{
    protected function taskName(): string
    {
        return 'maintenance';
    }
}
