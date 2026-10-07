<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Media\MediaService;
use App\Service\Storage\TempDirectoryManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:media:cleanup', description: 'Deletes orphaned media files (not referenced by any MediaFile) and old temp directories.')]
final class MediaCleanupCommand extends Command
{
    public function __construct(
        private readonly MediaService $media,
        private readonly TempDirectoryManager $temp,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Only list what would be deleted')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Do not ask for confirmation')
            ->addOption('min-age', null, InputOption::VALUE_REQUIRED, 'Only files older than this many hours', '24');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $minAge = max(1, (int) $input->getOption('min-age')) * 3600;

        $candidates = $this->media->cleanupOrphanFiles($minAge, true);
        if ([] === $candidates) {
            $io->success('No orphaned media files.');
        } else {
            $io->listing($candidates);
            if ($input->getOption('dry-run')) {
                $io->note(\count($candidates).' file(s) would be deleted (dry run).');
            } elseif ($input->getOption('force') || $io->confirm(\sprintf('Delete these %d orphaned file(s)?', \count($candidates)), false)) {
                $deleted = $this->media->cleanupOrphanFiles($minAge, false);
                $io->success(\count($deleted).' file(s) deleted.');
            } else {
                $io->note('Aborted.');
            }
        }

        if (!$input->getOption('dry-run')) {
            $removed = $this->temp->purgeOlderThan(6 * 3600);
            $io->writeln(\sprintf('Removed %d old temp director%s.', $removed, 1 === $removed ? 'y' : 'ies'));
        }

        return Command::SUCCESS;
    }
}
