<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Controller\AppController;
use App\Enum\AuditAction;
use App\Enum\CollectionVisibility;
use App\Repository\UserCollectionRepository;
use App\Service\Audit\AuditLogger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** Moderation of user collections (e.g. inappropriate public collection names). */
#[Route('/admin/collections')]
#[IsGranted('ROLE_ADMIN')]
final class CollectionAdminController extends AppController
{
    #[Route('', name: 'admin_collection_index', methods: ['GET'])]
    public function index(Request $request, UserCollectionRepository $collections): Response
    {
        $visibility = CollectionVisibility::tryFrom((string) $request->query->get('visibility'));

        return $this->render('admin/collection/index.html.twig', [
            'collections' => $collections->adminSearch($request->query->get('q'), $visibility, $this->page($request)),
            'visibility' => $visibility,
        ]);
    }

    #[Route('/{id}/private', name: 'admin_collection_private', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function makePrivate(int $id, Request $request, UserCollectionRepository $collections, EntityManagerInterface $em, AuditLogger $audit): Response
    {
        $this->assertFormCsrf($request);
        $collection = $collections->find($id) ?? throw $this->createNotFoundException();
        $collection->setVisibility(CollectionVisibility::Private);
        $audit->log(AuditAction::CollectionRemoved, $collection, ['action' => 'made_private', 'name' => $collection->getName()]);
        $em->flush();
        $this->addFlash('success', 'Collection is now private.');

        return $this->redirectToRoute('admin_collection_index');
    }

    #[Route('/{id}/delete', name: 'admin_collection_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(int $id, Request $request, UserCollectionRepository $collections, EntityManagerInterface $em, AuditLogger $audit): Response
    {
        $this->assertFormCsrf($request);
        $collection = $collections->find($id) ?? throw $this->createNotFoundException();
        $audit->log(AuditAction::CollectionRemoved, $collection, ['action' => 'deleted', 'name' => $collection->getName(), 'owner' => $collection->getUser()->getId()]);
        $em->remove($collection);
        $em->flush();
        $this->addFlash('success', 'Collection deleted.');

        return $this->redirectToRoute('admin_collection_index');
    }
}
