<?php

declare(strict_types=1);

namespace App\Controller\Public;

use App\Controller\AppController;
use App\Repository\PageRepository;
use App\Service\Seo\SeoService;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** Editable static pages (About, Privacy, Terms, Disclaimer, ...) at clean URLs like /about. */
final class PageController extends AppController
{
    #[Route('/{slug}', name: 'app_page', requirements: ['slug' => '[a-z0-9]+(?:-[a-z0-9]+)*'], methods: ['GET'], priority: -100)]
    public function show(string $slug, PageRepository $pages, SeoService $seo): Response
    {
        $page = $pages->findPublishedBySlug($slug) ?? throw $this->createNotFoundException();

        $response = $this->render('page/show.html.twig', [
            'page' => $page,
            'seo' => $seo->forPage($page),
        ]);
        $response->setLastModified($page->getUpdatedAt());

        return $response;
    }
}
