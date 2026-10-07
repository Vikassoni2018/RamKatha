<?php

declare(strict_types=1);

namespace App\Controller\Public;

use App\Controller\AppController;
use App\Service\I18n\ContentLocalizer;
use App\Service\Seo\SeoService;
use App\Service\Story\NavigationService;
use App\Service\Story\StoryFilterFactory;
use App\Service\Story\StorySearchService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

final class SearchController extends AppController
{
    #[Route('/search', name: 'app_search', methods: ['GET'])]
    public function search(Request $request, StoryFilterFactory $filters, StorySearchService $search, NavigationService $navigation, SeoService $seo, TranslatorInterface $translator): Response
    {
        $filter = $filters->fromRequest($request);
        $results = $search->search($filter, $this->page($request));

        $meta = $seo->forPrivate(null !== $filter->query
            ? $translator->trans('search.meta_title_query', ['%query%' => $filter->query])
            : $translator->trans('search.title'));

        return $this->render('search/index.html.twig', [
            'filter' => $filter,
            'results' => $results,
            'terms' => $search->highlightTerms($filter->query),
            'facets' => [
                'category' => $navigation->terms('category'),
                'devta' => $navigation->terms('deity'),
                'granth' => $navigation->terms('scripture'),
            ],
            'seo' => $meta,
        ]);
    }

    #[Route('/api/search/suggest', name: 'api_search_suggest', methods: ['GET'])]
    public function suggest(Request $request, StorySearchService $search, ContentLocalizer $content): JsonResponse
    {
        $results = array_map(fn (array $row) => [
            'title' => $content->text($row, 'title'),
            'url' => $this->generateUrl('app_story_show', ['slug' => $row['slug']]),
        ], $search->suggest((string) $request->query->get('q', '')));

        $response = $this->json(['results' => $results]);
        $response->setPublic();
        $response->setMaxAge(60);

        return $response;
    }
}
