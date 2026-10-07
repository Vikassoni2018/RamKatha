<?php

declare(strict_types=1);

namespace App\Service\Social;

use App\Entity\MediaFile;
use App\Entity\SocialAsset;
use App\Entity\Story;
use App\Entity\User;
use App\Enum\AssetStatus;
use App\Exception\DomainRuleException;
use App\Exception\FeatureNotConfiguredException;
use App\Message\GenerateAudiogram;
use App\Service\Audio\AudioGenerationException;
use App\Service\Audio\FfmpegService;
use App\Service\Config\FeatureFlags;
use App\Service\Media\ImageProcessor;
use App\Service\Storage\StorageInterface;
use App\Service\Storage\TempDirectoryManager;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Optional audiogram: a square MP4 made from the story cover (with a subtle branded band) and the
 * first seconds of the narration, rendered locally with FFmpeg and stored in public/media/social.
 *
 * Text is not burned into the video because correct Devanagari shaping depends on the FFmpeg/GD
 * build (HarfBuzz). Use the editable caption for the title instead.
 */
final class AudiogramService
{
    public const PREVIEW_SECONDS = 45;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly FeatureFlags $features,
        private readonly FfmpegService $ffmpeg,
        private readonly ImageProcessor $images,
        private readonly TempDirectoryManager $temp,
        #[Autowire(service: 'app.storage.public')]
        private readonly StorageInterface $publicStorage,
        #[Autowire(service: 'app.storage.private')]
        private readonly StorageInterface $privateStorage,
        private readonly MessageBusInterface $bus,
        #[Autowire(service: 'monolog.logger.social')]
        private readonly LoggerInterface $logger,
    ) {
    }

    public function request(Story $story, ?User $by): SocialAsset
    {
        if (null !== $reason = $this->features->audiogramUnavailableReason()) {
            throw new FeatureNotConfiguredException('audiogram', 'Feature is not configured: '.$reason);
        }
        if (!$story->hasPlayableAudio()) {
            throw new DomainRuleException('The story needs generated audio before an audiogram can be created.');
        }
        if (null === $story->getCoverImage()) {
            throw new DomainRuleException('The story needs a cover image for the audiogram.');
        }
        $asset = new SocialAsset($story);
        $asset->setCreatedBy($by);
        $this->em->persist($asset);
        $this->em->flush();
        $this->bus->dispatch(new GenerateAudiogram((int) $asset->getId()));

        return $asset;
    }

    public function generate(SocialAsset $asset): void
    {
        if (AssetStatus::Ready === $asset->getStatus()) {
            return;
        }
        $asset->markProcessing();
        $this->em->flush();

        $workDir = $this->temp->create('audiogram');
        try {
            if (null !== $reason = $this->features->audiogramUnavailableReason()) {
                throw new AudioGenerationException($reason);
            }
            $story = $asset->getStory();
            $cover = $story->getCoverImage();
            $audio = $story->getCurrentAudio();
            if (!$cover instanceof MediaFile || null === $audio?->getStorageKey()) {
                throw new AudioGenerationException('Cover image or audio missing.');
            }

            $coverPath = $this->publicStorage->localPath($cover->getVariantKey(MediaFile::VARIANT_LARGE));
            $frame = $workDir.'/frame.png';
            if ($this->images->supports($cover->getMimeType())) {
                $this->images->squareCrop($coverPath, $frame, $cover->getMimeType(), 1080);
            } else {
                $frame = $coverPath;
            }

            $output = $workDir.'/audiogram.mp4';
            $this->ffmpeg->renderAudiogram($frame, $this->privateStorage->localPath($audio->getStorageKey()), $output, self::PREVIEW_SECONDS);

            $key = \sprintf('social/%s/%s.mp4', date('Y/m'), $asset->getUuid()->toRfc4122());
            $this->publicStorage->storeFile($output, $key);
            $asset->markReady($key, (int) filesize($output), min(self::PREVIEW_SECONDS, (int) $audio->getDurationSeconds()));
            $this->em->flush();
        } catch (\Throwable $e) {
            $this->logger->error('Audiogram generation failed: {error}', ['error' => $e->getMessage()]);
            $asset->markFailed($e->getMessage());
            $this->em->flush();
        } finally {
            $this->temp->remove($workDir);
        }
    }
}
