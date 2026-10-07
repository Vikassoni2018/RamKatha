<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Controller\AppController;
use App\Entity\MediaFile;
use App\Exception\DomainRuleException;
use App\Form\Admin\MediaUploadType;
use App\Repository\MediaFileRepository;
use App\Service\Media\MediaService;
use App\Service\Media\MediaValidationException;
use App\Service\Storage\StorageInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Asset\Packages;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/media')]
#[IsGranted('ROLE_AUTHOR')]
final class MediaAdminController extends AppController
{
    public function __construct(
        private readonly MediaFileRepository $media,
        private readonly MediaService $service,
        #[Autowire(service: 'app.storage.public')]
        private readonly StorageInterface $publicStorage,
    ) {
    }

    #[Route('', name: 'admin_media_index', methods: ['GET', 'POST'])]
    public function index(Request $request): Response
    {
        $form = $this->createForm(MediaUploadType::class);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $file = $form->get('file')->getData();
                $media = $this->service->upload($file, $this->requireUser(), $form->get('altText')->getData());
                $this->addFlash('success', 'Image uploaded: '.$media->getOriginalName());

                return $this->redirectToRoute('admin_media_index');
            } catch (MediaValidationException $e) {
                $this->addFlash('error', $e->getMessage());
            }
        }

        return $this->render('admin/media/index.html.twig', [
            'items' => $this->media->searchPaginated($request->query->get('q'), $this->page($request)),
            'form' => $form,
        ]);
    }

    #[Route('/{id}', name: 'admin_media_show', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function show(int $id, Request $request, EntityManagerInterface $em): Response
    {
        $media = $this->find($id);
        if ($request->isMethod('POST')) {
            $this->assertFormCsrf($request);
            $media->setAltText(mb_substr(trim((string) $request->request->get('altText', '')), 0, 255) ?: null);
            $media->touchUpdatedAt();
            $em->flush();
            $this->addFlash('success', 'Alternative text saved.');

            return $this->redirectToRoute('admin_media_show', ['id' => $id]);
        }

        return $this->render('admin/media/show.html.twig', ['media' => $media, 'usages' => $this->service->findReferences($media)]);
    }

    #[Route('/{id}/delete', name: 'admin_media_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_EDITOR')]
    public function delete(int $id, Request $request): Response
    {
        $this->assertFormCsrf($request);
        $media = $this->find($id);
        try {
            $this->service->delete($media, $this->requireUser());
            $this->addFlash('success', 'Image deleted.');

            return $this->redirectToRoute('admin_media_index');
        } catch (DomainRuleException $e) {
            $this->addFlash('error', $e->getMessage());

            return $this->redirectToRoute('admin_media_show', ['id' => $id]);
        }
    }

    /** JSON list for the media picker. */
    #[Route('/api/list', name: 'admin_media_api', methods: ['GET'])]
    public function api(Request $request, Packages $packages): JsonResponse
    {
        $page = $this->media->searchPaginated($request->query->get('q'), $this->page($request), 24);

        return $this->json([
            'items' => array_map(fn (MediaFile $m) => $this->serialize($m, $packages), $page->getResults()),
            'total' => $page->getTotalItems(),
            'hasMore' => $page->hasNextPage(),
        ]);
    }

    /** AJAX upload used by the media picker. */
    #[Route('/api/upload', name: 'admin_media_upload_api', methods: ['POST'])]
    public function apiUpload(Request $request, Packages $packages, #[Autowire(service: 'limiter.api_write')] RateLimiterFactory $limiter): JsonResponse
    {
        if (!$this->isCsrfTokenValid('api', (string) ($request->headers->get('X-CSRF-Token') ?? $request->request->get('_token')))) {
            return $this->jsonError('Invalid CSRF token.', 403);
        }
        $user = $this->requireUser();
        if (!$limiter->create('upload-'.$user->getId())->consume()->isAccepted()) {
            return $this->jsonError('Too many uploads, please wait a moment.', 429);
        }
        $file = $request->files->get('file');
        if (!$file instanceof UploadedFile) {
            return $this->jsonError('No file received.', 422);
        }
        try {
            $media = $this->service->upload($file, $user);
        } catch (MediaValidationException $e) {
            return $this->jsonError($e->getMessage(), 422);
        }

        return $this->json(['item' => $this->serialize($media, $packages)], 201);
    }

    /** @return array<string, mixed> */
    private function serialize(MediaFile $m, Packages $packages): array
    {
        $thumbUrl = (string) $this->publicStorage->publicUrl($m->getVariantKey(MediaFile::VARIANT_THUMB));

        return [
            'id' => $m->getId(),
            'name' => $m->getOriginalName(),
            'alt' => $m->getAltText(),
            'width' => $m->getWidth(),
            'height' => $m->getHeight(),
            'thumb' => $packages->getUrl(ltrim($thumbUrl, '/')),
        ];
    }

    private function find(int $id): MediaFile
    {
        return $this->media->find($id) ?? throw $this->createNotFoundException();
    }
}
