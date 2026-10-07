<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\SystemHeartbeatRepository;
use App\Service\System\PeriodicTasks;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Cron fallback for hosting without persistent workers. Run it every minute:
 *
 *   * * * * * cd /var/www/katha && php bin/console app:cron:run --consume=50 >> var/log/cron.log 2>&1
 *
 * It runs every periodic task whose interval has elapsed (each under its own lock) and, with
 * --consume, processes queued Messenger jobs (audio, Facebook, emails) for up to N seconds.
 */
#[AsCommand(name: 'app:cron:run', description: 'Runs due periodic tasks and (optionally) processes queued jobs — cron entry point.')]
final class CronRunCommand extends Command
{
    public function __construct(
        private readonly PeriodicTasks $tasks,
        private readonly SystemHeartbeatRepository $heartbeats,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('consume', null, InputOption::VALUE_REQUIRED, 'Also consume the async queue for at most N seconds (0 = do not consume)', '0')
            ->addOption('task', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Run only these tasks (ignores intervals)')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Run all tasks regardless of their interval');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $only = (array) $input->getOption('task');
        $force = (bool) $input->getOption('force') || [] !== $only;
        $heartbeats = $this->heartbeats->findAllIndexed();
        $now = time();
        $failures = 0;

        foreach (PeriodicTasks::INTERVALS as $name => $minutes) {
            if ([] !== $only && !\in_array($name, $only, true)) {
                continue;
            }
            $last = isset($heartbeats[$name]) ? $heartbeats[$name]->getLastRunAt()->getTimestamp() : 0;
            // Small tolerance so a task scheduled "every minute" is not skipped by cron jitter.
            if (!$force && $now - $last < $minutes * 60 - 15) {
                continue;
            }
            $result = $this->tasks->run($name);
            $io->writeln(\sprintf('[%s] %-18s %-7s %s', date('Y-m-d H:i:s'), $name, $result['status'], $result['message'] ?? ''));
            if ('error' === $result['status']) {
                ++$failures;
            }
        }

        $seconds = max(0, (int) $input->getOption('consume'));
        if ($seconds > 0 && null !== $application = $this->getApplication()) {
            $consume = $application->find('messenger:consume');
            $consume->run(new ArrayInput([
                'receivers' => ['async'],
                '--time-limit' => (string) $seconds,
                '--limit' => '25',
                '--memory-limit' => '256M',
                '--no-reset' => true,
            ]), $output);
        }

        return 0 === $failures ? Command::SUCCESS : Command::FAILURE;
    }
}
