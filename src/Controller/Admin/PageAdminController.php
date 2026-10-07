<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Controller\AppController;
use App\Entity\Page;
use App\Enum\AuditAction;
use App\Form\Admin\PageType;
use App\Repository\PageRepository;
use App\Service\Audit\AuditLogger;
use App\Service\Cache\ContentCacheInvalidator;
use App\Service\Content\HtmlContentSanitizer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** Editable static pages (About, Privacy, Terms, Disclaimer, Support policy, Copyright...). */
#[Route('/admin/pages')]
#[IsGranted('ROLE_EDITOR')]
final class PageAdminController extends AppController
{
    public function __construct(
        private readonly PageRepository $pages,
        private readonly EntityManagerInterface $em,
        private readonly HtmlContentSanitizer $sanitizer,
        private readonly AuditLogger $audit,
        private readonly ContentCacheInvalidator $cache,
        private readonly RouterInterface $router,
    ) {
    }

    #[Route('', name: 'admin_page_index', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('admin/page/index.html.twig', ['pages' => $this->pages->findAllOrdered()]);
    }

    #[Route('/new', name: 'admin_page_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        return $this->handle(new Page(), $request, true);
    }

    #[Route('/{id}/edit', name: 'admin_page_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(int $id, Request $request): Response
    {
        return $this->handle($this->pages->find($id) ?? throw $this->createNotFoundException(), $request, false);
    }

    #[Route('/{id}/delete', name: 'admin_page_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function delete(int $id, Request $request): Response
    {
        $this->assertFormCsrf($request);
        $page = $this->pages->find($id) ?? throw $this->createNotFoundException();
        $this->audit->log(AuditAction::PageUpdated, $page, ['action' => 'deleted', 'slug' => $page->getSlug()]);
        $this->em->remove($page);
        $this->em->flush();
        $this->cache->pages();
        $this->addFlash('success', 'Page deleted.');

        return $this->redirectToRoute('admin_page_index');
    }

    private function handle(Page $page, Request $request, bool $isNew): Response
    {
        $form = $this->createForm(PageType::class, $page);
        $form->handleRequest($request);

        if ($form->isSubmitted()) {
            if ($this->isReservedSlug($page->getSlug())) {
                $form->get('slug')->addError(new FormError('This URL is used by the application. Choose another slug.'));
            }
            if ($form->isValid()) {
                $page->setBody($this->sanitizer->sanitize($page->getBody()));
                $page->setUpdatedBy($this->requireUser());
                $page->touchUpdatedAt();
                $this->em->persist($page);
                $this->audit->log(AuditAction::PageUpdated, $page, ['action' => $isNew ? 'created' : 'updated', 'slug' => $page->getSlug()]);
                $this->em->flush();
                $this->cache->pages();
                $this->addFlash('success', 'Page saved.');

                return $this->redirectToRoute('admin_page_edit', ['id' => $page->getId()]);
            }
        }

        return $this->render('admin/page/form.html.twig', ['form' => $form, 'page' => $page, 'isNew' => $isNew]);
    }

    /** A page slug must not shadow an application route such as /katha or /login. */
    private function isReservedSlug(string $slug): bool
    {
        if ('' === $slug) {
            return false;
        }
        try {
            $match = $this->router->match('/'.$slug);

            // Localized routes are named app_page.hi / app_page.en; _canonical_route holds the plain name.
            return 'app_page' !== ($match['_canonical_route'] ?? $match['_route'] ?? null);
        } catch (\Throwable) {
            return false;
        }
    }
}
