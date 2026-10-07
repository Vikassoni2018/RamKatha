<?php

declare(strict_types=1);

namespace App\Service\Audio;

use App\Entity\AudioAsset;
use App\Enum\AudioStatus;
use App\Enum\AuditAction;
use App\Enum\NotificationType;
use App\Enum\StoryStatus;
use App\Enum\StoryTransition;
use App\Repository\AudioAssetRepository;
use App\Service\Audio\Tts\TtsException;
use App\Service\Audio\Tts\TtsOptions;
use App\Service\Audio\Tts\TtsProviderRegistry;
use App\Service\Audit\AuditLogger;
use App\Service\Cache\ContentCacheInvalidator;
use App\Service\Notification\NotificationService;
use App\Service\Storage\StorageException;
use App\Service\Storage\StorageInterface;
use App\Service\Storage\TempDirectoryManager;
use App\Service\Story\StoryWorkflowService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The narration pipeline:
 *   story text → sanitise/strip HTML → normalise punctuation → pronunciation dictionary
 *   → chunk → TTS per chunk → validate chunks → merge + loudness-normalise (FFmpeg)
 *   → probe duration → store MP3 on the private disk → update AudioAsset/Story → clean temp files.
 *
 * Failures are recorded on the asset with an admin-readable message; nothing is faked.
 */
final class AudioGenerationService
{
    /** A "processing" asset older than this is considered abandoned by a crashed worker. */
    public const STALE_AFTER_MINUTES = 60;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AudioAssetRepository $assets,
        private readonly TtsProviderRegistry $tts,
        private readonly AudioTextPreprocessor $preprocessor,
        private readonly AudioChunker $chunker,
        private readonly FfmpegService $ffmpeg,
        private readonly TempDirectoryManager $temp,
        #[Autowire(service: 'app.storage.private')]
        private readonly StorageInterface $privateStorage,
        private readonly StoryWorkflowService $workflow,
        private readonly AuditLogger $audit,
        private readonly NotificationService $notifications,
        private readonly ContentCacheInvalidator $cache,
        private readonly ClockInterface $clock,
        #[Autowire(service: 'monolog.logger.audio')]
        private readonly LoggerInterface $logger,
        #[Autowire(env: 'int:TTS_CHUNK_MAX_CHARS')]
        private readonly int $chunkMaxChars = 900,
    ) {
    }

    /** @return bool true if audio was generated, false if skipped or failed */
    public function generate(AudioAsset $asset): bool
    {
        if (\in_array($asset->getStatus(), [AudioStatus::Ready, AudioStatus::Disabled], true)) {
            return false; // idempotent: message redelivered after success
        }
        $now = $this->clock->now();
        if (!$this->assets->claimForProcessing($asset, $now->modify('-'.self::STALE_AFTER_MINUTES.' minutes'))) {
            $this->logger->info('Audio asset {id} is already being processed.', ['id' => $asset->getId()]);

            return false;
        }
        $this->em->refresh($asset);
        $asset->markProcessing($now);
        $story = $asset->getStory();
        if (null === $story->getCurrentAudio() || !$story->hasPlayableAudio()) {
            $story->setAudioStatus(AudioStatus::Processing);
        }
        $this->em->flush();

        $workDir = null;
        try {
            $provider = $this->tts->getActive();
            if (!$provider->isConfigured()) {
                throw new AudioGenerationException('Feature is not configured: '.$provider->getConfigurationError());
            }
            if (!$this->ffmpeg->isFfmpegAvailable()) {
                throw new AudioGenerationException('Feature is not configured: FFmpeg is not available (FFMPEG_BINARY). It is required to merge and encode narration.');
            }

            $text = $this->preprocessor->prepareStory($story);
            if ('' === $text) {
                throw new AudioGenerationException('The story has no narratable text.');
            }
            $chunks = $this->chunker->split($text, $this->chunkMaxChars);
            $this->logger->info('Generating audio for story #{story}: {chunks} chunk(s), provider {provider}', ['story' => $story->getId(), 'chunks' => \count($chunks), 'provider' => $provider->getName()]);

            $workDir = $this->temp->create('audio');
            $options = new TtsOptions((string) $asset->getVoice(), $this->tts->getLanguage());
            $files = [];
            foreach ($chunks as $index => $chunk) {
                $file = \sprintf('%s/chunk-%04d.%s', $workDir, $index, $provider->getOutputExtension());
                $provider->synthesize($chunk, $file, $options);
                $this->assertValidChunk($file, $index);
                $files[] = $file;
            }

            $merged = $workDir.'/final.mp3';
            $this->ffmpeg->concatenateToMp3($files, $merged);
            $duration = $this->ffmpeg->probeDuration($merged);
            if (null === $duration || $duration < 1) {
                throw new AudioGenerationException('The merged audio file is invalid (duration could not be determined).');
            }

            $key = \sprintf('audio/%s/%s.mp3', $now->format('Y/m'), $asset->getUuid()->toRfc4122());
            $this->privateStorage->storeFile($merged, $key);
            $checksum = (string) hash_file('sha256', $merged);
            $fileSize = (int) filesize($merged);

            $previous = $story->getCurrentAudio();
            $asset->markReady($key, $this->downloadName($story->getSlug()), 'audio/mpeg', $fileSize, (int) round($duration), $checksum, hash('sha256', $text), \count($chunks), $this->clock->now());
            $story->setCurrentAudio($asset);
            $story->setAudioStatus(AudioStatus::Ready);
            $story->setAudioDurationSeconds((int) round($duration));
            $story->setAudioOutdated(false);

            if (null !== $previous && $previous !== $asset) {
                $this->retire($previous);
            }

            $this->audit->log(AuditAction::AudioGenerated, $story, ['asset' => $asset->getId(), 'duration' => (int) round($duration), 'chunks' => \count($chunks)]);
            $this->em->flush();

            if (StoryStatus::AudioPending === $story->getStatus()) {
                $this->workflow->apply($story, StoryTransition::MarkAudioGenerated, null);
            }
            if ($story->isPublished()) {
                $this->cache->stories();
            }
            $this->notifications->notify(NotificationType::AudioGenerated, 'Audio ready: '.$story->getTitle(), \sprintf('Duration %d:%02d', intdiv((int) $duration, 60), (int) $duration % 60), '/admin/stories/'.$story->getId().'/edit');

            return true;
        } catch (AudioGenerationException|TtsException|StorageException $e) {
            $this->fail($asset, $e->getMessage());

            return false;
        } catch (\Throwable $e) {
            $this->logger->error('Unexpected audio pipeline error: {error}', ['error' => $e->getMessage(), 'exception' => $e]);
            $this->fail($asset, 'Unexpected error: '.$e->getMessage());

            return false;
        } finally {
            if (null !== $workDir) {
                $this->temp->remove($workDir);
            }
        }
    }

    private function assertValidChunk(string $file, int $index): void
    {
        if (!is_file($file) || filesize($file) < 100) {
            throw new AudioGenerationException(\sprintf('Chunk %d produced no audio.', $index + 1));
        }
        $duration = $this->ffmpeg->probeDuration($file);
        if (null !== $duration && $duration <= 0.05) {
            throw new AudioGenerationException(\sprintf('Chunk %d is empty (0 seconds).', $index + 1));
        }
    }

    private function fail(AudioAsset $asset, string $message): void
    {
        $this->logger->error('Audio generation failed for asset {id}: {error}', ['id' => $asset->getId(), 'error' => $message]);
        if (!$this->em->isOpen()) {
            return;
        }
        $story = $asset->getStory();
        $asset->markFailed($message, $this->clock->now());
        if (!$story->hasPlayableAudio()) {
            $story->setAudioStatus(AudioStatus::Failed);
        }
        $this->audit->log(AuditAction::AudioFailed, $story, ['asset' => $asset->getId(), 'error' => $message]);
        $this->em->flush();
        $this->notifications->notify(NotificationType::AudioFailed, 'Audio generation failed: '.$story->getTitle(), $message, '/admin/stories/'.$story->getId().'/edit');
    }

    /** Disables a superseded asset and deletes its file to free disk space. */
    private function retire(AudioAsset $previous): void
    {
        $previous->disable();
        if (null !== $previous->getStorageKey()) {
            try {
                $this->privateStorage->delete($previous->getStorageKey());
            } catch (StorageException $e) {
                $this->logger->warning('Could not delete superseded audio {key}: {error}', ['key' => $previous->getStorageKey(), 'error' => $e->getMessage()]);
            }
        }
    }

    private function downloadName(string $slug): string
    {
        return substr($slug, 0, 100).'.mp3';
    }
}
