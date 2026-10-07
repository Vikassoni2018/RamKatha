<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * First-party analytics events. Each event maps to a counter column of DailyStoryMetric.
 */
enum MetricEvent: string
{
    case StoryView = 'story_view';
    case UniqueView = 'unique_view';
    case AudioPlay = 'audio_play';
    case AudioProgress25 = 'audio_25';
    case AudioProgress50 = 'audio_50';
    case AudioProgress75 = 'audio_75';
    case AudioCompleted = 'audio_complete';
    case BookmarkCreated = 'bookmark';
    case CollectionAdd = 'collection_add';
    case ShareClick = 'share';
    case DonationInitiated = 'donation_initiated';
    case DonationDeclared = 'donation_declared';

    /** Column in daily_story_metric that this event increments. */
    public function column(): string
    {
        return match ($this) {
            self::StoryView => 'views',
            self::UniqueView => 'unique_views',
            self::AudioPlay => 'audio_starts',
            self::AudioProgress25 => 'audio_progress25',
            self::AudioProgress50 => 'audio_progress50',
            self::AudioProgress75 => 'audio_progress75',
            self::AudioCompleted => 'audio_completions',
            self::BookmarkCreated => 'bookmarks',
            self::CollectionAdd => 'collection_adds',
            self::ShareClick => 'shares',
            self::DonationInitiated => 'donations_initiated',
            self::DonationDeclared => 'donations_declared',
        };
    }

    /**
     * Events the browser may report anonymously. Everything else is recorded server-side
     * when the corresponding action actually happens (so it cannot be spoofed).
     */
    public function isClientReportable(): bool
    {
        return \in_array($this, [
            self::StoryView, self::UniqueView, self::AudioPlay, self::AudioProgress25,
            self::AudioProgress50, self::AudioProgress75, self::AudioCompleted, self::ShareClick,
        ], true);
    }
}
