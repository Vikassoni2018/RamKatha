<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Editorial workflow states of a story.
 * Allowed transitions between these states are defined in {@see StoryTransition}.
 */
enum StoryStatus: string
{
    case Draft = 'draft';
    case UnderReview = 'under_review';
    case SourceVerified = 'source_verified';
    case AudioPending = 'audio_pending';
    case AudioGenerated = 'audio_generated';
    case Approved = 'approved';
    case Scheduled = 'scheduled';
    case Published = 'published';
    case Archived = 'archived';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::UnderReview => 'Under review',
            self::SourceVerified => 'Source verified',
            self::AudioPending => 'Audio pending',
            self::AudioGenerated => 'Audio generated',
            self::Approved => 'Approved',
            self::Scheduled => 'Scheduled',
            self::Published => 'Published',
            self::Archived => 'Archived',
        };
    }

    /** Visual variant used by the badge component. */
    public function badge(): string
    {
        return match ($this) {
            self::Draft => 'neutral',
            self::UnderReview, self::AudioPending => 'warning',
            self::SourceVerified, self::AudioGenerated => 'info',
            self::Approved, self::Scheduled => 'accent',
            self::Published => 'success',
            self::Archived => 'muted',
        };
    }

    /** Ordered pipeline used to render the workflow timeline in the admin. */
    public static function pipeline(): array
    {
        return [
            self::Draft,
            self::UnderReview,
            self::SourceVerified,
            self::AudioPending,
            self::AudioGenerated,
            self::Approved,
            self::Scheduled,
            self::Published,
        ];
    }

    /** States in which the story content is considered "locked" for authors. */
    public function isEditableByAuthor(): bool
    {
        return self::Draft === $this;
    }
}
