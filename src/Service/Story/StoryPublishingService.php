<?php

declare(strict_types=1);

namespace App\Service\Story;

use App\Entity\Story;
use App\Entity\User;
use App\Enum\AudioStatus;
use App\Enum\AuditAction;
use App\Enum\NotificationType;
use App\Enum\StoryStatus;
use App\Service\Audio\AudioRequestService;
use App\Service\Audit\AuditLogger;
use App\Service\Cache\ContentCacheInvalidator;
use App\Service\Config\FeatureFlags;
use App\Service\Notification\NotificationService;
use App\Service\Settings\SettingsRegistry;
use App\Service\Settings\SettingsService;
use App\Service\Social\SocialPostService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\ClockInterface;

/**
 * State changes in/out of "published" and their side effects. publish()/unpublish() run inside the
 * workflow transaction; afterPublish() runs after commit (cache, notifications, social, audio).
 */
final class StoryPublishingService
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly ContentCacheInvalidator $cache,
        private readonly NotificationService $notifications,
        private readonly SocialPostService $social,
        private readonly AudioRequestService $audioRequests,
        private readonly FeatureFlags $features,
        private readonly SettingsService $settings,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function publish(Story $story, ?User $actor): void
    {
        $now = $this->clock->now();
        $story->setStatus(StoryStatus::Published);
        $story->setPublishedAt($now);
        $story->setScheduledAt(null);
        $story->setArchivedAt(null);
        $this->audit->log(AuditAction::StoryPublished, $story, ['slug' => $story->getSlug()], actor: $actor);
    }

    public function unpublish(Story $story, ?User $actor): void
    {
        $story->setStatus(StoryStatus::Approved);
        $story->setPublishedAt(null);
        $this->audit->log(AuditAction::StoryUnpublished, $story, ['slug' => $story->getSlug()], actor: $actor);
    }

    /** Post-commit side effects. Failures here never roll back the publication. */
    public function afterPublish(Story $story, ?User $actor): void
    {
        $this->cache->stories();

        try {
            $this->notifications->notify(NotificationType::StoryPublished, 'Story published: '.$story->getTitle(), null, '/admin/stories/'.$story->getId().'/edit');
        } catch (\Throwable $e) {
            $this->logger->warning('Publish notification failed: {e}', ['e' => $e->getMessage()]);
        }

        // Automatic narration for stories published without audio.
        if ($story->isAudioEnabled()
            && !$story->hasPlayableAudio()
            && !\in_array($story->getAudioStatus(), [AudioStatus::Queued, AudioStatus::Processing, AudioStatus::Pending], true)
            && $this->settings->getBool(SettingsRegistry::AUDIO_AUTO_GENERATE)
            && $this->features->audioGenerationAvailable()) {
            try {
                $this->audioRequests->request($story, $actor);
            } catch (\Throwable $e) {
                $this->logger->warning('Automatic audio request failed for story {id}: {e}', ['id' => $story->getId(), 'e' => $e->getMessage()]);
            }
        }

        try {
            $this->social->autoQueueForStory($story);
        } catch (\Throwable $e) {
            $this->logger->warning('Automatic social post failed for story {id}: {e}', ['id' => $story->getId(), 'e' => $e->getMessage()]);
        }
    }
}
