<?php

declare(strict_types=1);

namespace App\Controller\Public;

use App\Controller\AppController;
use App\Enum\AudioStatus;
use App\Repository\AudioAssetRepository;
use App\Security\Voter\StoryVoter;
use App\Service\Storage\StorageInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

/**
 * Streams narration from the PRIVATE disk with HTTP Range support (seeking works).
 * Published audio is publicly cacheable; unpublished audio is only for staff with preview rights.
 * Set APP_X_SENDFILE=1 to let Apache mod_xsendfile / nginx X-Accel serve the bytes.
 */
final class AudioStreamController extends AppController
{
    #[Route('/audio/{uuid}.mp3', name: 'app_audio_stream', requirements: ['uuid' => '[0-9a-f\-]{36}'], methods: ['GET', 'HEAD'])]
    public function serve(
        string $uuid,
        Request $request,
        AudioAssetRepository $assets,
        #[Autowire(service: 'app.storage.private')]
        StorageInterface $privateStorage,
        #[Autowire(env: 'bool:APP_X_SENDFILE')]
        bool $useSendfile = false,
    ): BinaryFileResponse {
        if (!Uuid::isValid($uuid)) {
            throw $this->createNotFoundException();
        }
        $asset = $assets->findOneByUuid(Uuid::fromString($uuid));
        if (null === $asset || AudioStatus::Ready !== $asset->getStatus() || null === $asset->getStorageKey()) {
            throw $this->createNotFoundException();
        }

        $story = $asset->getStory();
        $isPublic = $story->isPublished() && $story->getCurrentAudio()?->getId() === $asset->getId() && $story->isAudioEnabled();
        if (!$isPublic && !$this->isGranted(StoryVoter::PREVIEW, $story)) {
            throw $this->createNotFoundException();
        }

        $path = $privateStorage->localPath($asset->getStorageKey());
        if (!is_file($path)) {
            throw $this->createNotFoundException();
        }

        if ($useSendfile) {
            BinaryFileResponse::trustXSendfileTypeHeader();
        }
        $response = new BinaryFileResponse($path, 200, ['Content-Type' => 'audio/mpeg'], $isPublic, ResponseHeaderBag::DISPOSITION_INLINE, true, true);
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_INLINE, $asset->getFileName() ?? 'katha.mp3', 'katha.mp3');
        $response->headers->set('Accept-Ranges', 'bytes');
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        if ($isPublic) {
            $response->setPublic();
            $response->setMaxAge(86400 * 7);
            $response->setImmutable(); // a regenerated narration gets a new UUID/URL
        } else {
            $response->setPrivate();
            $response->headers->addCacheControlDirective('no-store');
            $response->headers->set('X-Robots-Tag', 'noindex');
        }
        $response->prepare($request);

        return $response;
    }
}
