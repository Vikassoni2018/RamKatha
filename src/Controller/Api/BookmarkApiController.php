<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Controller\AppController;
use App\Repository\StoryRepository;
use App\Service\Story\BookmarkService;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

final class BookmarkApiController extends AppController
{
    #[Route('/api/bookmarks/{uuid}', name: 'api_bookmark', requirements: ['uuid' => '[0-9a-f\-]{36}'], methods: ['POST'])]
    public function set(
        string $uuid,
        Request $request,
        StoryRepository $stories,
        BookmarkService $bookmarks,
        #[Autowire(service: 'limiter.api_write')]
        RateLimiterFactory $limiter,
    ): JsonResponse {
        $user = $this->currentUser();
        if (null === $user) {
            return $this->jsonError('कृपया पहले लॉग इन करें।', 401);
        }
        $payload = $this->jsonPayload($request);
        if (!$this->isApiCsrfValid($request, $payload)) {
            return $this->jsonError('Invalid CSRF token.', 403);
        }
        if (!$limiter->create('bookmark-'.$user->getId())->consume()->isAccepted()) {
            return $this->jsonError('कृपया थोड़ी देर बाद प्रयास करें।', 429);
        }
        if (!Uuid::isValid($uuid) || null === $story = $stories->findPublishedByUuid(Uuid::fromString($uuid))) {
            return $this->jsonError('कथा नहीं मिली।', 404);
        }

        $desired = isset($payload['bookmarked']) ? (bool) $payload['bookmarked'] : !$bookmarks->isBookmarked($user, $story);

        return $this->json(['bookmarked' => $bookmarks->set($user, $story, $desired)]);
    }
}
