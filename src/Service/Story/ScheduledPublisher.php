<?php

declare(strict_types=1);

namespace App\Service\Story;

use App\Enum\StoryStatus;
use App\Enum\StoryTransition;
use App\Repository\StoryRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Publishes scheduled stories whose time has come. Idempotent: each story is re-read and only
 * published while still "scheduled", so concurrent or repeated runs never republish content.
 * Callers wrap it in a Symfony Lock (see JobRunner) to avoid overlapping runs.
 */
final class ScheduledPublisher
{
    public function __construct(
        private readonly StoryRepository $stories,
        private readonly StoryWorkflowService $workflow,
        private readonly EntityManagerInterface $em,
        private readonly ClockInterface $clock,
        #[Autowire(service: 'monolog.logger.jobs')]
        private readonly LoggerInterface $logger,
    ) {
    }

    /** @return list<string> titles of published stories */
    public function publishDue(): array
    {
        $published = [];
        foreach ($this->stories->findDueScheduledIds($this->clock->now()) as $id) {
            $story = $this->stories->find($id);
            if (null === $story) {
                continue;
            }
            $this->em->refresh($story);
            if (StoryStatus::Scheduled !== $story->getStatus()) {
                continue;
            }
            try {
                $this->workflow->apply($story, StoryTransition::Publish, null);
                $published[] = $story->getTitle();
                $this->logger->info('Scheduled story published: {title} (#{id})', ['title' => $story->getTitle(), 'id' => $id]);
            } catch (\Throwable $e) {
                $this->logger->error('Scheduled publication of story #{id} failed: {error}', ['id' => $id, 'error' => $e->getMessage()]);
            }
        }

        return $published;
    }
}
