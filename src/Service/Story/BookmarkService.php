<?php

declare(strict_types=1);

namespace App\Service\Story;

use App\Entity\Story;
use App\Entity\User;
use App\Enum\MetricEvent;
use App\Repository\BookmarkRepository;
use App\Service\Analytics\AnalyticsRecorder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;

/**
 * Bookmarks are set to an explicit desired state (idempotent; double clicks and retries are safe).
 */
final class BookmarkService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly BookmarkRepository $bookmarks,
        private readonly AnalyticsRecorder $analytics,
        private readonly ClockInterface $clock,
    ) {
    }

    public function isBookmarked(User $user, Story $story): bool
    {
        return null !== $this->bookmarks->findOneByUserAndStory($user, $story);
    }

    /** @return bool the resulting state */
    public function set(User $user, Story $story, bool $bookmarked): bool
    {
        $conn = $this->em->getConnection();
        if ($bookmarked) {
            $inserted = $conn->executeStatement(
                'INSERT IGNORE INTO bookmark (user_id, story_id, created_at) VALUES (?, ?, ?)',
                [$user->getId(), $story->getId(), $this->clock->now()->format('Y-m-d H:i:s')],
            );
            if ($inserted > 0) {
                $this->analytics->record((int) $story->getId(), MetricEvent::BookmarkCreated);
            }

            return true;
        }

        $conn->executeStatement('DELETE FROM bookmark WHERE user_id = ? AND story_id = ?', [$user->getId(), $story->getId()]);

        return false;
    }

    public function toggle(User $user, Story $story): bool
    {
        return $this->set($user, $story, !$this->isBookmarked($user, $story));
    }
}
