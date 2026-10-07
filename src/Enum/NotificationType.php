<?php

declare(strict_types=1);

namespace App\Enum;

enum NotificationType: string
{
    case StoryPublished = 'story_published';
    case AudioGenerated = 'audio_generated';
    case AudioFailed = 'audio_failed';
    case SocialPublished = 'social_published';
    case SocialFailed = 'social_failed';
    case DonationDeclared = 'donation_declared';
    case ContactMessage = 'contact_message';
    case SystemError = 'system_error';

    public function level(): NotificationLevel
    {
        return match ($this) {
            self::StoryPublished, self::AudioGenerated, self::SocialPublished => NotificationLevel::Success,
            self::AudioFailed, self::SocialFailed, self::SystemError => NotificationLevel::Error,
            self::DonationDeclared, self::ContactMessage => NotificationLevel::Info,
        };
    }

    /** Failures are worth an email to the site contact address (if mail is configured). */
    public function shouldEmail(): bool
    {
        return NotificationLevel::Error === $this->level();
    }
}
