<?php

declare(strict_types=1);

namespace App\Service\Audio;

use App\Entity\AudioAsset;
use App\Entity\Story;
use App\Entity\User;
use App\Enum\AudioStatus;
use App\Enum\AuditAction;
use App\Exception\DomainRuleException;
use App\Exception\FeatureNotConfiguredException;
use App\Message\GenerateStoryAudio;
use App\Repository\AudioAssetRepository;
use App\Service\Audio\Tts\TtsProviderRegistry;
use App\Service\Audit\AuditLogger;
use App\Service\Config\FeatureFlags;
use App\Service\Settings\SettingsRegistry;
use App\Service\Settings\SettingsService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Queues narration for a story. Idempotent: if a job is already pending for the story the
 * existing asset is returned instead of queueing a duplicate.
 */
final class AudioRequestService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AudioAssetRepository $assets,
        private readonly TtsProviderRegistry $tts,
        private readonly FeatureFlags $features,
        private readonly SettingsService $settings,
        private readonly MessageBusInterface $bus,
        private readonly AuditLogger $audit,
    ) {
    }

    /**
     * @throws FeatureNotConfiguredException when TTS or FFmpeg is missing
     * @throws DomainRuleException when audio is disabled for the story
     */
    public function request(Story $story, ?User $requestedBy, bool $flush = true): AudioAsset
    {
        if (!$story->isAudioEnabled()) {
            throw new DomainRuleException('Audio is disabled for this story. Enable it in the story audio settings first.');
        }
        if (null !== $reason = $this->features->audioGenerationUnavailableReason()) {
            throw new FeatureNotConfiguredException('tts', 'Feature is not configured: '.$reason);
        }
        if ('' === trim(strip_tags($story->getBody()))) {
            throw new DomainRuleException('The story has no text to narrate.');
        }

        $existing = $this->assets->findInProgressForStory($story);
        if (null !== $existing) {
            return $existing;
        }

        $provider = $this->tts->getActive();
        $asset = new AudioAsset($story, $story->getLanguage(), $provider->getName(), $this->resolveVoice($story));
        $asset->setRequestedBy($requestedBy);
        $asset->markQueued();
        $this->em->persist($asset);

        // Keep "ready" while a regeneration is pending so the public player keeps working.
        if (AudioStatus::Ready !== $story->getAudioStatus() || !$story->hasPlayableAudio()) {
            $story->setAudioStatus(AudioStatus::Queued);
        }

        $this->audit->log(AuditAction::AudioRequested, $story, ['provider' => $provider->getName(), 'voice' => $asset->getVoice()], actor: $requestedBy);

        if ($flush) {
            $this->em->flush();
            $this->dispatch($asset);
        }

        return $asset;
    }

    /** Dispatch after the asset has an id (call after flush when $flush=false was used). */
    public function dispatch(AudioAsset $asset): void
    {
        $this->bus->dispatch(new GenerateStoryAudio((int) $asset->getId()));
    }

    public function resolveVoice(Story $story): string
    {
        $voice = $story->getTtsVoice();
        if (null === $voice || '' === trim($voice)) {
            $voice = $this->settings->getString(SettingsRegistry::TTS_DEFAULT_VOICE);
        }
        if ('' === trim($voice)) {
            $voice = $this->tts->getActive()->getDefaultVoice();
        }

        return trim($voice);
    }
}
