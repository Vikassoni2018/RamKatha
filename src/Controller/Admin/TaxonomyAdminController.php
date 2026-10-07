<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Controller\AppController;
use App\Entity\Taxonomy\AbstractTaxonomy;
use App\Enum\AuditAction;
use App\Form\Admin\TaxonomyType;
use App\Service\Audit\AuditLogger;
use App\Service\Cache\ContentCacheInvalidator;
use App\Service\Slug\SlugService;
use App\Service\Story\TaxonomyRegistry;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** One CRUD controller for categories, tags, deities, scriptures, series and festivals. */
#[Route('/admin/taxonomy/{type}', requirements: ['type' => 'category|tag|deity|scripture|series|festival'])]
#[IsGranted('ROLE_EDITOR')]
final class TaxonomyAdminController extends AppController
{
    public function __construct(
        private readonly TaxonomyRegistry $registry,
        private readonly EntityManagerInterface $em,
        private readonly SlugService $slugs,
        private readonly AuditLogger $audit,
        private readonly ContentCacheInvalidator $cache,
    ) {
    }

    #[Route('', name: 'admin_taxonomy_index', methods: ['GET'])]
    public function index(string $type, Request $request): Response
    {
        $repository = $this->registry->repository($type);

        return $this->render('admin/taxonomy/index.html.twig', [
            'type' => $type,
            'label' => $this->registry->englishPlural($type),
            'terms' => $repository->searchPaginated($request->query->get('q'), $this->page($request)),
            'counts' => $repository->countPublishedStoriesPerTerm(),
        ]);
    }

    #[Route('/new', name: 'admin_taxonomy_new', methods: ['GET', 'POST'])]
    public function new(string $type, Request $request): Response
    {
        $class = $this->registry->classFor($type);
        /** @var AbstractTaxonomy $term */
        $term = new $class();

        return $this->handleForm($type, $term, $request, true);
    }

    #[Route('/{id}/edit', name: 'admin_taxonomy_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(string $type, int $id, Request $request): Response
    {
        return $this->handleForm($type, $this->find($type, $id), $request, false);
    }

    #[Route('/{id}/delete', name: 'admin_taxonomy_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(string $type, int $id, Request $request): Response
    {
        $this->assertFormCsrf($request);
        $term = $this->find($type, $id);
        $used = $this->registry->repository($type)->countStoriesUsing($term);
        if ($used > 0) {
            $this->addFlash('error', \sprintf('“%s” is used by %d stor%s. Deactivate it instead, or remove it from those stories first.', $term->getName(), $used, 1 === $used ? 'y' : 'ies'));

            return $this->redirectToRoute('admin_taxonomy_edit', ['type' => $type, 'id' => $id]);
        }
        $this->audit->log(AuditAction::TaxonomyChanged, $term, ['action' => 'deleted', 'type' => $type, 'name' => $term->getName()]);
        $this->em->remove($term);
        $this->em->flush();
        $this->cache->taxonomy();
        $this->addFlash('success', 'Deleted.');

        return $this->redirectToRoute('admin_taxonomy_index', ['type' => $type]);
    }

    private function handleForm(string $type, AbstractTaxonomy $term, Request $request, bool $isNew): Response
    {
        $form = $this->createForm(TaxonomyType::class, $term, ['data_class' => $term::class]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $term->setSlug($this->slugs->uniqueSlug($term::class, '' !== trim($term->getSlug()) ? $term->getSlug() : ($term->getNameEn() ?: $term->getName()), $term->getId()));
            $term->touchUpdatedAt();
            $this->em->persist($term);
            $this->audit->log(AuditAction::TaxonomyChanged, $term, ['action' => $isNew ? 'created' : 'updated', 'type' => $type, 'name' => $term->getName()]);
            $this->em->flush();
            $this->cache->taxonomy();
            $this->addFlash('success', $isNew ? 'Created.' : 'Saved.');

            return $this->redirectToRoute('admin_taxonomy_index', ['type' => $type]);
        }

        return $this->render('admin/taxonomy/form.html.twig', [
            'type' => $type,
            'label' => $this->registry->englishPlural($type),
            'term' => $term,
            'form' => $form,
            'isNew' => $isNew,
            'segment' => $this->registry->segment($type),
        ]);
    }

    private function find(string $type, int $id): AbstractTaxonomy
    {
        return $this->registry->repository($type)->find($id) ?? throw $this->createNotFoundException();
    }
}
