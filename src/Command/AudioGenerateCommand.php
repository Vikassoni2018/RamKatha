<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Story;
use App\Enum\AudioStatus;
use App\Exception\DomainRuleException;
use App\Exception\FeatureNotConfiguredException;
use App\Repository\StoryRepository;
use App\Service\Audio\AudioGenerationService;
use App\Service\Audio\AudioRequestService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:audio:generate', description: 'Generates Hindi narration for a story (synchronously by default).')]
final class AudioGenerateCommand extends Command
{
    public function __construct(
        private readonly StoryRepository $stories,
        private readonly AudioRequestService $requests,
        private readonly AudioGenerationService $generator,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('story', InputArgument::REQUIRED, 'Story id or slug')
            ->addOption('queue', null, InputOption::VALUE_NONE, 'Only queue the job for the background worker');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $ref = (string) $input->getArgument('story');
        $story = ctype_digit($ref) ? $this->stories->find((int) $ref) : $this->stories->findOneBy(['slug' => $ref]);
        if (!$story instanceof Story) {
            $io->error('Story not found: '.$ref);

            return Command::FAILURE;
        }

        try {
            $asset = $this->requests->request($story, null);
        } catch (FeatureNotConfiguredException|DomainRuleException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        if ($input->getOption('queue')) {
            $io->success(\sprintf('Queued audio job #%d for “%s”.', $asset->getId(), $story->getTitle()));

            return Command::SUCCESS;
        }

        $io->writeln(\sprintf('Generating narration for “%s” (job #%d)…', $story->getTitle(), $asset->getId()));
        $this->generator->generate($asset);

        if (AudioStatus::Ready === $asset->getStatus()) {
            $io->success(\sprintf('Done: %d seconds, %d chunk(s).', $asset->getDurationSeconds(), $asset->getChunkCount()));

            return Command::SUCCESS;
        }
        $io->error('Generation failed: '.($asset->getErrorMessage() ?? 'unknown error').' (status '.$asset->getStatus()->value.')');

        return Command::FAILURE;
    }
}
