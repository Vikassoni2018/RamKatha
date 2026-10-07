<?php

declare(strict_types=1);

namespace App\Service\Audio;

use App\Entity\AudioAsset;
use App\Enum\AudioStatus;
use App\Exception\DomainRuleException;
use App\Message\GenerateStoryAudio;
use App\Repository\AudioAssetRepository;
use App\Service\Config\FeatureFlags;
use App\Service\Settings\SettingsRegistry;
use App\Service\Settings\SettingsService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/** Manual and automatic retries of failed / stuck narration jobs. */
final class AudioRetryService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AudioAssetRepository $assets,
        private readonly FeatureFlags $features,
        private readonly SettingsService $settings,
        private readonly MessageBusInterface $bus,
        private readonly ClockInterface $clock,
    ) {
    }

    public function retry(AudioAsset $asset): void
    {
        if (!\in_array($asset->getStatus(), [AudioStatus::Failed, AudioStatus::Queued, AudioStatus::Pending], true)) {
            throw new DomainRuleException('Only failed or queued audio can be retried.');
        }
        $asset->markQueued();
        $story = $asset->getStory();
        if (!$story->hasPlayableAudio()) {
            $story->setAudioStatus(AudioStatus::Queued);
        }
        $this->em->flush();
        $this->bus->dispatch(new GenerateStoryAudio((int) $asset->getId()));
    }

    /**
     * Cron/scheduler: re-queues failed jobs (bounded attempts, 15 min back-off) and recovers jobs
     * abandoned by a crashed worker. Does nothing while TTS/FFmpeg are not configured.
     *
     * @return int number of jobs re-queued
     */
    public function retryAutomatically(): int
    {
        if (!$this->features->audioGenerationAvailable()) {
            return 0;
        }
        $now = $this->clock->now();
        $count = 0;

        foreach ($this->assets->findStaleProcessing($now->modify('-'.AudioGenerationService::STALE_AFTER_MINUTES.' minutes')) as $stale) {
            $stale->markFailed('The worker stopped before finishing (timeout or crash). Retrying automatically.', $now);
        }
        $this->em->flush();

        $maxAttempts = $this->settings->getInt(SettingsRegistry::AUDIO_MAX_ATTEMPTS);
        foreach ($this->assets->findRetryable($maxAttempts, $now->modify('-15 minutes')) as $asset) {
            $this->retry($asset);
            ++$count;
        }

        // Queued long ago but never picked up (worker down when queued with a sync fallback etc.).
        foreach ($this->assets->findQueuedBefore($now->modify('-30 minutes')) as $asset) {
            $this->bus->dispatch(new GenerateStoryAudio((int) $asset->getId()));
            $asset->setUpdatedAt($now);
            ++$count;
        }
        $this->em->flush();

        return $count;
    }
}
