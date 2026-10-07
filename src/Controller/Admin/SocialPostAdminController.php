<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Controller\AppController;
use App\Entity\SocialPost;
use App\Enum\SocialPostStatus;
use App\Exception\DomainRuleException;
use App\Exception\FeatureNotConfiguredException;
use App\Form\Admin\SocialPostType;
use App\Repository\SocialPostRepository;
use App\Service\Social\SocialCaptionRenderer;
use App\Service\Social\SocialPostService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/social')]
#[IsGranted('ROLE_ADMIN')]
final class SocialPostAdminController extends AppController
{
    public function __construct(
        private readonly SocialPostRepository $posts,
        private readonly SocialPostService $service,
        #[Autowire(param: 'app.timezone')]
        private readonly string $timezone,
    ) {
    }

    #[Route('', name: 'admin_social_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $status = SocialPostStatus::tryFrom((string) $request->query->get('status'));

        return $this->render('admin/social/index.html.twig', [
            'posts' => $this->posts->searchPaginated($status, $this->page($request)),
            'status' => $status,
            'counts' => $this->posts->countByStatus(),
        ]);
    }

    #[Route('/{id}', name: 'admin_social_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(int $id, Request $request, EntityManagerInterface $em): Response
    {
        $post = $this->find($id);
        $form = $this->createForm(SocialPostType::class, $post, ['timezone' => $this->timezone, 'disabled' => !$post->getStatus()->isEditable()]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid() && $post->getStatus()->isEditable()) {
            $post->touchUpdatedAt();
            $em->flush();
            if ($request->request->has('queue')) {
                return $this->queue($post);
            }
            $this->addFlash('success', 'Caption saved.');

            return $this->redirectToRoute('admin_social_edit', ['id' => $id]);
        }

        return $this->render('admin/social/edit.html.twig', [
            'post' => $post,
            'form' => $form,
            'placeholders' => SocialCaptionRenderer::PLACEHOLDERS,
        ]);
    }

    #[Route('/{id}/retry', name: 'admin_social_retry', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function retry(int $id, Request $request): Response
    {
        $this->assertFormCsrf($request);
        $post = $this->find($id);
        try {
            $this->service->retry($post);
            $this->addFlash('success', 'Retry queued.');
        } catch (DomainRuleException|FeatureNotConfiguredException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('admin_social_edit', ['id' => $id]);
    }

    #[Route('/{id}/delete', name: 'admin_social_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(int $id, Request $request, EntityManagerInterface $em): Response
    {
        $this->assertFormCsrf($request);
        $post = $this->find($id);
        if (!\in_array($post->getStatus(), [SocialPostStatus::Draft, SocialPostStatus::Failed, SocialPostStatus::Queued], true)) {
            $this->addFlash('error', 'Published posts are kept for the record.');

            return $this->redirectToRoute('admin_social_edit', ['id' => $id]);
        }
        $em->remove($post);
        $em->flush();
        $this->addFlash('success', 'Post deleted.');

        return $this->redirectToRoute('admin_social_index');
    }

    private function queue(SocialPost $post): Response
    {
        try {
            $this->service->queue($post, $post->getScheduledAt());
            $this->addFlash('success', null !== $post->getScheduledAt() ? 'Post scheduled.' : 'Post queued for publishing.');
        } catch (DomainRuleException|FeatureNotConfiguredException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('admin_social_edit', ['id' => $post->getId()]);
    }

    private function find(int $id): SocialPost
    {
        return $this->posts->find($id) ?? throw $this->createNotFoundException();
    }
}
