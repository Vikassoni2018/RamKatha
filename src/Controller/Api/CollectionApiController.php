<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Controller\AppController;
use App\Entity\Story;
use App\Entity\User;
use App\Entity\UserCollection;
use App\Exception\DomainRuleException;
use App\Repository\StoryRepository;
use App\Repository\UserCollectionRepository;
use App\Security\Voter\CollectionVoter;
use App\Service\Story\CollectionService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

/** JSON endpoints behind the "add to collection" dialog and drag & drop ordering. */
#[Route('/api/collections')]
final class CollectionApiController extends AppController
{
    public function __construct(
        private readonly UserCollectionRepository $collections,
        private readonly StoryRepository $stories,
        private readonly CollectionService $service,
    ) {
    }

    #[Route('', name: 'api_collections', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $user = $this->currentUser();
        if (null === $user) {
            return $this->jsonError('कृपया पहले लॉग इन करें।', 401);
        }
        $story = $this->storyFrom((string) $request->query->get('story'));
        if (null === $story) {
            return $this->jsonError('कथा नहीं मिली।', 404);
        }

        return $this->json(['collections' => $this->pickerData($user, $story)]);
    }

    #[Route('', name: 'api_collections_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $user = $this->currentUser();
        if (null === $user) {
            return $this->jsonError('कृपया पहले लॉग इन करें।', 401);
        }
        $payload = $this->jsonPayload($request);
        if (!$this->isApiCsrfValid($request, $payload)) {
            return $this->jsonError('Invalid CSRF token.', 403);
        }
        $name = trim((string) ($payload['name'] ?? ''));
        if (mb_strlen($name) < 2 || mb_strlen($name) > 120) {
            return $this->jsonError('संग्रह का नाम 2 से 120 अक्षरों का हो।', 422);
        }
        $story = $this->storyFrom((string) ($payload['story'] ?? ''));

        try {
            $collection = $this->service->create($user, strip_tags($name));
            if (null !== $story) {
                $this->service->addStory($collection, $story);
            }
        } catch (DomainRuleException $e) {
            return $this->jsonError($e->getMessage(), 422);
        }

        return $this->json(['collections' => null !== $story ? $this->pickerData($user, $story) : []], 201);
    }

    #[Route('/{uuid}/items', name: 'api_collection_items', requirements: ['uuid' => '[0-9a-f\-]{36}'], methods: ['POST', 'DELETE'])]
    public function items(string $uuid, Request $request): JsonResponse
    {
        if (null === $this->currentUser()) {
            return $this->jsonError('कृपया पहले लॉग इन करें।', 401);
        }
        $collection = $this->ownedCollection($uuid);
        $payload = $this->jsonPayload($request);
        if (!$this->isApiCsrfValid($request, $payload)) {
            return $this->jsonError('Invalid CSRF token.', 403);
        }
        $story = $this->storyFrom((string) ($payload['story'] ?? ''));
        if (null === $story) {
            return $this->jsonError('कथा नहीं मिली।', 404);
        }

        try {
            if ($request->isMethod('DELETE')) {
                $this->service->removeStory($collection, $story);
                $contains = false;
            } else {
                $this->service->addStory($collection, $story);
                $contains = true;
            }
        } catch (DomainRuleException $e) {
            return $this->jsonError($e->getMessage(), 422);
        }

        return $this->json(['contains' => $contains, 'count' => $collection->getItems()->count()]);
    }

    #[Route('/{uuid}/order', name: 'api_collection_order', requirements: ['uuid' => '[0-9a-f\-]{36}'], methods: ['POST'])]
    public function order(string $uuid, Request $request): JsonResponse
    {
        if (null === $this->currentUser()) {
            return $this->jsonError('कृपया पहले लॉग इन करें।', 401);
        }
        $collection = $this->ownedCollection($uuid);
        $payload = $this->jsonPayload($request);
        if (!$this->isApiCsrfValid($request, $payload)) {
            return $this->jsonError('Invalid CSRF token.', 403);
        }
        $order = array_values(array_filter((array) ($payload['order'] ?? []), static fn ($id) => \is_string($id) && Uuid::isValid($id)));
        $this->service->reorder($collection, \array_slice($order, 0, CollectionService::MAX_ITEMS_PER_COLLECTION));

        return $this->json(['ok' => true]);
    }

    private function ownedCollection(string $uuid): UserCollection
    {
        if (!Uuid::isValid($uuid)) {
            throw $this->createNotFoundException();
        }
        $collection = $this->collections->findOneByUuid(Uuid::fromString($uuid)) ?? throw $this->createNotFoundException();
        // Ownership check through the voter: other users' collections are never editable.
        $this->denyAccessUnlessGranted(CollectionVoter::EDIT, $collection);

        return $collection;
    }

    private function storyFrom(string $uuid): ?Story
    {
        return Uuid::isValid($uuid) ? $this->stories->findPublishedByUuid(Uuid::fromString($uuid)) : null;
    }

    /** @return list<array<string, mixed>> */
    private function pickerData(User $user, Story $story): array
    {
        return array_map(fn (array $c) => $c + [
            'itemsUrl' => $this->generateUrl('api_collection_items', ['uuid' => $c['uuid']]),
        ], $this->collections->findPickerData($user, $story));
    }
}
