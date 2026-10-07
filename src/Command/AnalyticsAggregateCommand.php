<?php

declare(strict_types=1);

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'app:analytics:aggregate', description: 'Refreshes story counters and recency-weighted popularity scores.')]
final class AnalyticsAggregateCommand extends AbstractPeriodicTaskCommand
{
    protected function taskName(): string
    {
        return 'analytics';
    }
}
