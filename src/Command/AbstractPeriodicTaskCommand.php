<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\System\PeriodicTasks;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/** Base for single-task commands; each one is locked and records a heartbeat. */
abstract class AbstractPeriodicTaskCommand extends Command
{
    public function __construct(private readonly PeriodicTasks $tasks)
    {
        parent::__construct();
    }

    abstract protected function taskName(): string;

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $result = $this->tasks->run($this->taskName());
        $io = new SymfonyStyle($input, $output);
        match ($result['status']) {
            'ok' => $io->success($result['message'] ?? 'Done.'),
            'skipped' => $io->note('Skipped: another run is in progress.'),
            default => $io->error($result['message'] ?? 'Failed.'),
        };

        return 'error' === $result['status'] ? Command::FAILURE : Command::SUCCESS;
    }
}
