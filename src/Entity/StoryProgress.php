<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\StoryProgressRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/** Per-user listening/reading progress for a story. */
#[ORM\Entity(repositoryClass: StoryProgressRepository::class)]
#[ORM\Table(name: 'story_progress')]
#[ORM\UniqueConstraint(name: 'uniq_progress_user_story', columns: ['user_id', 'story_id'])]
#[ORM\Index(name: 'idx_progress_user_updated', columns: ['user_id', 'updated_at'])]
#[ORM\Index(name: 'idx_progress_user_played', columns: ['user_id', 'last_played_at'])]
#[ORM\Index(name: 'idx_progress_user_read', columns: ['user_id', 'last_read_at'])]
class StoryProgress
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(options: ['default' => 0])]
    private int $audioPositionSeconds = 0;

    #[ORM\Column(nullable: true)]
    private ?int $audioDurationSeconds = null;

    #[ORM\Column(type: Types::SMALLINT, options: ['default' => 0])]
    private int $readingProgressPercent = 0;

    #[ORM\Column(type: Types::SMALLINT, options: ['default' => 0])]
    private int $audioProgressPercent = 0;

    #[ORM\Column(options: ['default' => false])]
    private bool $completed = false;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $completedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lastPlayedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lastReadAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private User $user,
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Story $story,
    ) {
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getStory(): Story
    {
        return $this->story;
    }

    public function getAudioPositionSeconds(): int
    {
        return $this->audioPositionSeconds;
    }

    public function getAudioDurationSeconds(): ?int
    {
        return $this->audioDurationSeconds;
    }

    public function getReadingProgressPercent(): int
    {
        return $this->readingProgressPercent;
    }

    public function getAudioProgressPercent(): int
    {
        return $this->audioProgressPercent;
    }

    public function isCompleted(): bool
    {
        return $this->completed;
    }

    public function getCompletedAt(): ?\DateTimeImmutable
    {
        return $this->completedAt;
    }

    public function getLastPlayedAt(): ?\DateTimeImmutable
    {
        return $this->lastPlayedAt;
    }

    public function getLastReadAt(): ?\DateTimeImmutable
    {
        return $this->lastReadAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function updateAudio(int $positionSeconds, ?int $durationSeconds, \DateTimeImmutable $now): void
    {
        $this->audioPositionSeconds = max(0, $positionSeconds);
        if (null !== $durationSeconds && $durationSeconds > 0) {
            $this->audioDurationSeconds = $durationSeconds;
            $this->audioProgressPercent = (int) min(100, round($this->audioPositionSeconds / $durationSeconds * 100));
        }
        $this->lastPlayedAt = $now;
        $this->updatedAt = $now;
    }

    public function updateReading(int $percent, \DateTimeImmutable $now): void
    {
        // Reading progress only moves forward; scrolling back up should not lose progress.
        $this->readingProgressPercent = max($this->readingProgressPercent, min(100, max(0, $percent)));
        $this->lastReadAt = $now;
        $this->updatedAt = $now;
    }

    public function markCompleted(\DateTimeImmutable $now): bool
    {
        if ($this->completed) {
            return false;
        }
        $this->completed = true;
        $this->completedAt = $now;
        $this->updatedAt = $now;

        return true;
    }

    /** Whether there is meaningful listening progress worth resuming. */
    public function isResumable(): bool
    {
        return !$this->completed && $this->audioPositionSeconds >= 5;
    }
}
