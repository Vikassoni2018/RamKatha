<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Explicit transition table for the editorial workflow.
 *
 *   draft ─submit→ under_review ─verify_source→ source_verified ─request_audio→ audio_pending
 *     ↑                                               │                             │ (system)
 *     └────────────── request_changes ────────────────┤                     audio_generated
 *                                                     └──────approve──────┬─────────┘
 *                                                                         ↓
 *                                         approved ─schedule→ scheduled ─(cron)→ published
 *                                             └──────────publish─────────────────↗
 */
enum StoryTransition: string
{
    case Submit = 'submit';
    case RequestChanges = 'request_changes';
    case VerifySource = 'verify_source';
    case RequestAudio = 'request_audio';
    case MarkAudioGenerated = 'mark_audio_generated';
    case Approve = 'approve';
    case Schedule = 'schedule';
    case Unschedule = 'unschedule';
    case Publish = 'publish';
    case Unpublish = 'unpublish';
    case Archive = 'archive';
    case Restore = 'restore';

    /** @return StoryStatus[] */
    public function fromStatuses(): array
    {
        return match ($this) {
            self::Submit => [StoryStatus::Draft],
            self::RequestChanges => [StoryStatus::UnderReview, StoryStatus::SourceVerified, StoryStatus::AudioPending, StoryStatus::AudioGenerated, StoryStatus::Approved],
            self::VerifySource => [StoryStatus::UnderReview],
            self::RequestAudio => [StoryStatus::SourceVerified, StoryStatus::AudioGenerated, StoryStatus::AudioPending],
            self::MarkAudioGenerated => [StoryStatus::AudioPending],
            self::Approve => [StoryStatus::SourceVerified, StoryStatus::AudioPending, StoryStatus::AudioGenerated],
            self::Schedule => [StoryStatus::Approved],
            self::Unschedule => [StoryStatus::Scheduled],
            self::Publish => [StoryStatus::Approved, StoryStatus::Scheduled],
            self::Unpublish => [StoryStatus::Published],
            self::Archive => [StoryStatus::Draft, StoryStatus::Approved, StoryStatus::Published, StoryStatus::UnderReview],
            self::Restore => [StoryStatus::Archived],
        };
    }

    public function to(): StoryStatus
    {
        return match ($this) {
            self::Submit => StoryStatus::UnderReview,
            self::RequestChanges => StoryStatus::Draft,
            self::VerifySource => StoryStatus::SourceVerified,
            self::RequestAudio => StoryStatus::AudioPending,
            self::MarkAudioGenerated => StoryStatus::AudioGenerated,
            self::Approve => StoryStatus::Approved,
            self::Schedule => StoryStatus::Scheduled,
            self::Unschedule => StoryStatus::Approved,
            self::Publish => StoryStatus::Published,
            self::Unpublish => StoryStatus::Approved,
            self::Archive => StoryStatus::Archived,
            self::Restore => StoryStatus::Draft,
        };
    }

    public function canApplyFrom(StoryStatus $status): bool
    {
        return \in_array($status, $this->fromStatuses(), true);
    }

    /** Transitions that are performed by the system only (never offered as buttons). */
    public function isSystemOnly(): bool
    {
        return self::MarkAudioGenerated === $this;
    }

    public function label(): string
    {
        return match ($this) {
            self::Submit => 'Submit for review',
            self::RequestChanges => 'Send back to draft',
            self::VerifySource => 'Verify source',
            self::RequestAudio => 'Generate audio',
            self::MarkAudioGenerated => 'Mark audio generated',
            self::Approve => 'Approve',
            self::Schedule => 'Schedule',
            self::Unschedule => 'Cancel schedule',
            self::Publish => 'Publish now',
            self::Unpublish => 'Unpublish',
            self::Archive => 'Archive',
            self::Restore => 'Restore to draft',
        };
    }

    /** Button style hint for the admin UI. */
    public function buttonVariant(): string
    {
        return match ($this) {
            self::Publish, self::Approve, self::VerifySource => 'primary',
            self::Archive, self::Unpublish => 'danger-outline',
            default => 'secondary',
        };
    }

    public function requiresConfirmation(): bool
    {
        return \in_array($this, [self::Publish, self::Unpublish, self::Archive, self::RequestChanges], true);
    }

    /** @return StoryTransition[] */
    public static function availableFrom(StoryStatus $status): array
    {
        return array_values(array_filter(
            self::cases(),
            static fn (self $t): bool => !$t->isSystemOnly() && $t->canApplyFrom($status),
        ));
    }
}
