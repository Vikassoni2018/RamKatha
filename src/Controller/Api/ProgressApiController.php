<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Controller\AppController;
use App\Repository\StoryRepository;
use App\Service\Story\StoryProgressService;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

/** Listening/reading progress for logged-in users (JSON; also accepts navigator.sendBeacon). */
final class ProgressApiController extends AppController
{
    #[Route('/api/progress', name: 'api_progress', methods: ['POST'])]
    public function save(
        Request $request,
        StoryRepository $stories,
        StoryProgressService $progress,
        #[Autowire(service: 'limiter.api_write')]
        RateLimiterFactory $limiter,
    ): JsonResponse {
        $user = $this->currentUser();
        if (null === $user) {
            return $this->jsonError('Login required.', 401);
        }
        $payload = $this->jsonPayload($request);
        if (!$this->isApiCsrfValid($request, $payload)) {
            return $this->jsonError('Invalid CSRF token.', 403);
        }
        if (!$limiter->create('progress-'.$user->getId())->consume()->isAccepted()) {
            return $this->jsonError('Too many requests.', 429);
        }

        $uuid = $payload['story'] ?? null;
        if (!\is_string($uuid) || !Uuid::isValid($uuid)) {
            return $this->jsonError('Invalid story.', 422);
        }
        $story = $stories->findPublishedByUuid(Uuid::fromString($uuid));
        if (null === $story) {
            return $this->jsonError('Story not found.', 404);
        }

        $result = ['ok' => true];
        if (isset($payload['position']) && is_numeric($payload['position'])) {
            $duration = isset($payload['duration']) && is_numeric($payload['duration']) ? (int) $payload['duration'] : null;
            $result += $progress->saveAudio($user, $story, (int) $payload['position'], $duration);
        }
        if (isset($payload['reading']) && is_numeric($payload['reading'])) {
            $entry = $progress->saveReading($user, $story, (int) $payload['reading']);
            $result['reading'] = $entry->getReadingProgressPercent();
        }

        return $this->json($result);
    }
}
