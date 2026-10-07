<?php

declare(strict_types=1);

namespace App\Enum;

enum AuditAction: string
{
    case StoryCreated = 'story.created';
    case StoryUpdated = 'story.updated';
    case StoryTransition = 'story.transition';
    case StoryPublished = 'story.published';
    case StoryUnpublished = 'story.unpublished';
    case StoryScheduled = 'story.scheduled';
    case StoryArchived = 'story.archived';
    case StorySourceVerified = 'story.source_verified';
    case StoryVerificationReset = 'story.verification_reset';
    case StorySlugChanged = 'story.slug_changed';
    case StoryRevisionRestored = 'story.revision_restored';
    case AudioRequested = 'audio.requested';
    case AudioGenerated = 'audio.generated';
    case AudioFailed = 'audio.failed';
    case AudioDisabled = 'audio.disabled';
    case DonationVerified = 'donation.verified';
    case DonationRejected = 'donation.rejected';
    case DonationUpdated = 'donation.updated';
    case SocialPostCreated = 'social.created';
    case SocialPublished = 'social.published';
    case SocialFailed = 'social.failed';
    case SettingsChanged = 'settings.changed';
    case UserRoleChanged = 'user.role_changed';
    case UserDeactivated = 'user.deactivated';
    case UserActivated = 'user.activated';
    case UserCreated = 'user.created';
    case MediaUploaded = 'media.uploaded';
    case MediaDeleted = 'media.deleted';
    case TaxonomyChanged = 'taxonomy.changed';
    case PageUpdated = 'page.updated';
    case PronunciationChanged = 'pronunciation.changed';
    case CollectionRemoved = 'collection.removed';
    case FailedJobRetried = 'jobs.failed_retried';
    case FailedJobRemoved = 'jobs.failed_removed';
    case DataExported = 'data.exported';

    public function label(): string
    {
        return ucfirst(str_replace(['.', '_'], [' · ', ' '], $this->value));
    }

    public function badge(): string
    {
        return match ($this) {
            self::AudioFailed, self::SocialFailed, self::DonationRejected, self::UserDeactivated, self::MediaDeleted, self::CollectionRemoved, self::StoryArchived, self::StoryUnpublished => 'danger',
            self::StoryPublished, self::StorySourceVerified, self::AudioGenerated, self::DonationVerified, self::SocialPublished, self::UserActivated => 'success',
            self::UserRoleChanged, self::SettingsChanged, self::StoryVerificationReset => 'warning',
            default => 'neutral',
        };
    }
}
