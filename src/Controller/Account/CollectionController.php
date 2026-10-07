<?php

declare(strict_types=1);

namespace App\Controller\Account;

use App\Controller\AppController;
use App\Entity\UserCollection;
use App\Enum\CollectionVisibility;
use App\Exception\DomainRuleException;
use App\Form\CollectionFormType;
use App\Repository\UserCollectionRepository;
use App\Security\Voter\CollectionVoter;
use App\Service\Config\FeatureFlags;
use App\Service\Seo\SeoService;
use App\Service\Story\CollectionService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\Translation\TranslatorInterface;

final class CollectionController extends AppController
{
    public function __construct(
        private readonly UserCollectionRepository $collections,
        private readonly CollectionService $service,
        private readonly FeatureFlags $features,
        private readonly SeoService $seo,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('/collections', name: 'app_collection_index', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('collection/index.html.twig', [
            'collections' => $this->collections->findForUserWithCounts($this->requireUser()),
            'seo' => $this->seo->forPrivate($this->translator->trans('collection.index.title')),
        ]);
    }

    #[Route('/collections/new', name: 'app_collection_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $collection = new UserCollection($this->requireUser());
        $form = $this->createForm(CollectionFormType::class, $collection, ['allow_public' => $this->features->publicCollectionsEnabled()]);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $created = $this->service->create($collection->getUser(), $collection->getName(), $collection->getDescription(), $collection->getVisibility());
                $this->addFlash('success', $this->translator->trans('collection.flash.created'));

                return $this->redirectToRoute('app_collection_show', ['uuid' => $created->getUuid()->toRfc4122()]);
            } catch (DomainRuleException $e) {
                $this->addFlash('error', $e->getMessage());
            }
        }

        return $this->render('collection/form.html.twig', ['form' => $form, 'collection' => null, 'seo' => $this->seo->forPrivate($this->translator->trans('collection.new_title'))]);
    }

    #[Route('/collections/{uuid}/edit', name: 'app_collection_edit', requirements: ['uuid' => '[0-9a-f\-]{36}'], methods: ['GET', 'POST'])]
    public function edit(string $uuid, Request $request): Response
    {
        $collection = $this->find($uuid);
        $this->denyAccessUnlessGranted(CollectionVoter::EDIT, $collection);
        $form = $this->createForm(CollectionFormType::class, $collection, ['allow_public' => $this->features->publicCollectionsEnabled()]);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $this->service->save($collection);
            $this->addFlash('success', $this->translator->trans('collection.flash.updated'));

            return $this->redirectToRoute('app_collection_show', ['uuid' => $uuid]);
        }

        return $this->render('collection/form.html.twig', ['form' => $form, 'collection' => $collection, 'seo' => $this->seo->forPrivate($this->translator->trans('collection.edit_title'))]);
    }

    #[Route('/collections/{uuid}/delete', name: 'app_collection_delete', requirements: ['uuid' => '[0-9a-f\-]{36}'], methods: ['POST'])]
    public function delete(string $uuid, Request $request): Response
    {
        $this->assertFormCsrf($request);
        $collection = $this->find($uuid);
        $this->denyAccessUnlessGranted(CollectionVoter::EDIT, $collection);
        $this->service->delete($collection);
        $this->addFlash('success', $this->translator->trans('collection.flash.deleted'));

        return $this->redirectToRoute('app_collection_index');
    }

    #[Route('/collections/{uuid}/remove/{story}', name: 'app_collection_remove_item', requirements: ['uuid' => '[0-9a-f\-]{36}', 'story' => '[0-9a-f\-]{36}'], methods: ['POST'])]
    public function removeItem(string $uuid, string $story, Request $request, \App\Repository\StoryRepository $stories): Response
    {
        $this->assertFormCsrf($request);
        $collection = $this->find($uuid);
        $this->denyAccessUnlessGranted(CollectionVoter::EDIT, $collection);
        $storyEntity = Uuid::isValid($story) ? $stories->findOneByUuid(Uuid::fromString($story)) : null;
        if (null !== $storyEntity) {
            $this->service->removeStory($collection, $storyEntity);
            $this->addFlash('success', $this->translator->trans('collection.flash.item_removed'));
        }

        return $this->redirectToRoute('app_collection_show', ['uuid' => $uuid]);
    }

    /** Public/unlisted collections are viewable by anyone with the link; private only by the owner. */
    #[Route('/collection/{uuid}', name: 'app_collection_show', requirements: ['uuid' => '[0-9a-f\-]{36}'], methods: ['GET'])]
    public function show(string $uuid): Response
    {
        $collection = $this->find($uuid);
        if (!$this->isGranted(CollectionVoter::VIEW, $collection)) {
            throw $this->createNotFoundException(); // do not reveal that a private collection exists
        }

        $response = $this->render('collection/show.html.twig', [
            'collection' => $collection,
            'items' => $this->collections->findVisibleItems($collection),
            'isOwner' => $collection->isOwnedBy($this->currentUser()),
            'seo' => $this->seo->forCollection($collection),
        ]);
        if (CollectionVisibility::Public !== $collection->getVisibility()) {
            $response->headers->set('X-Robots-Tag', 'noindex');
        }

        return $response;
    }

    /** Discovery page for public collections (feature flag). */
    #[Route('/collection', name: 'app_collection_public', methods: ['GET'])]
    public function publicList(Request $request): Response
    {
        if (!$this->features->publicCollectionsEnabled()) {
            throw $this->createNotFoundException();
        }

        return $this->render('collection/public.html.twig', [
            'collections' => $this->collections->findPublicPaginated($this->page($request)),
            'seo' => $this->seo->forListing($this->translator->trans('collection.public.title'), $this->translator->trans('collection.public.lead'), 'app_collection_public'),
        ]);
    }

    private function find(string $uuid): UserCollection
    {
        if (!Uuid::isValid($uuid)) {
            throw $this->createNotFoundException();
        }

        return $this->collections->findOneByUuid(Uuid::fromString($uuid)) ?? throw $this->createNotFoundException();
    }
}
