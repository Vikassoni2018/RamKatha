<?php

declare(strict_types=1);

namespace App\Service\Story;

use App\Entity\Story;
use App\Entity\StoryProgress;
use App\Entity\User;
use App\Repository\StoryProgressRepository;
use App\Service\Settings\SettingsRegistry;
use App\Service\Settings\SettingsService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;

/** Server-side listening/reading progress for logged-in users (resume + continue listening). */
final class StoryProgressService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly StoryProgressRepository $progress,
        private readonly SettingsService $settings,
        private readonly ClockInterface $clock,
    ) {
    }

    public function get(User $user, Story $story): ?StoryProgress
    {
        return $this->progress->findOneByUserAndStory($user, $story);
    }

    /** @return array{completed: bool, justCompleted: bool, position: int} */
    public function saveAudio(User $user, Story $story, int $positionSeconds, ?int $durationSeconds): array
    {
        $now = $this->clock->now();
        $duration = $durationSeconds ?: $story->getAudioDurationSeconds();
        if (null !== $duration && $duration > 0) {
            $positionSeconds = min($positionSeconds, $duration);
        }

        $entry = $this->getOrCreate($user, $story);
        $entry->updateAudio(max(0, $positionSeconds), $duration, $now);

        $justCompleted = false;
        $threshold = $this->settings->getInt(SettingsRegistry::COMPLETION_THRESHOLD);
        if (null !== $duration && $duration > 0 && ($positionSeconds / $duration * 100) >= $threshold) {
            $justCompleted = $entry->markCompleted($now);
        }
        $this->em->flush();

        return ['completed' => $entry->isCompleted(), 'justCompleted' => $justCompleted, 'position' => $entry->getAudioPositionSeconds()];
    }

    public function saveReading(User $user, Story $story, int $percent): StoryProgress
    {
        $entry = $this->getOrCreate($user, $story);
        $entry->updateReading($percent, $this->clock->now());
        $this->em->flush();

        return $entry;
    }

    /**
     * Race-safe get-or-create: concurrent requests (e.g. beacon + timer) rely on the
     * (user, story) unique key instead of failing with a duplicate-key error.
     */
    private function getOrCreate(User $user, Story $story): StoryProgress
    {
        $existing = $this->progress->findOneByUserAndStory($user, $story);
        if (null !== $existing) {
            return $existing;
        }
        $now = $this->clock->now()->format('Y-m-d H:i:s');
        $this->em->getConnection()->executeStatement(
            'INSERT IGNORE INTO story_progress (user_id, story_id, audio_position_seconds, reading_progress_percent, audio_progress_percent, completed, created_at, updated_at)
             VALUES (?, ?, 0, 0, 0, 0, ?, ?)',
            [$user->getId(), $story->getId(), $now, $now],
        );

        return $this->progress->findOneByUserAndStory($user, $story) ?? throw new \RuntimeException('Progress row could not be created.');
    }
}
