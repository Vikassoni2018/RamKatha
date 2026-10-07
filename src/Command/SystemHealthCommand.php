<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\System\SystemHealthService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:system:health', description: 'Prints the system health checks (exit code 1 when a check fails).')]
final class SystemHealthCommand extends Command
{
    public function __construct(private readonly SystemHealthService $health)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $errors = 0;
        foreach ($this->health->checks() as $group => $checks) {
            $io->section($group);
            $rows = [];
            foreach ($checks as $check) {
                $symbol = match ($check['status']) {
                    SystemHealthService::OK => '<info>OK</info>',
                    SystemHealthService::WARN => '<comment>WARN</comment>',
                    default => '<error>FAIL</error>',
                };
                if (SystemHealthService::ERROR === $check['status']) {
                    ++$errors;
                }
                $rows[] = [$symbol, $check['label'], $check['value'], $check['help'] ?? ''];
            }
            $io->table(['', 'Check', 'Value', 'Hint'], $rows);
        }

        if ($errors > 0) {
            $io->error($errors.' check(s) failed.');

            return Command::FAILURE;
        }
        $io->success('No failing checks.');

        return Command::SUCCESS;
    }
}
