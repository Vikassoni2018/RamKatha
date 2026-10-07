<?php

declare(strict_types=1);

namespace App\Controller\Public;

use App\Controller\AppController;
use App\DTO\StoryListFilter;
use App\Repository\BookmarkRepository;
use App\Repository\StoryRepository;
use App\Repository\StorySlugHistoryRepository;
use App\Service\Seo\SeoService;
use App\Service\Settings\SettingsRegistry;
use App\Service\Settings\SettingsService;
use App\Service\Story\NavigationService;
use App\Service\Story\StoryFilterFactory;
use App\Service\Story\StoryPageDataBuilder;
use App\Service\Story\StoryProgressService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

final class StoryController extends AppController
{
    public function __construct(
        private readonly StoryRepository $stories,
        private readonly SettingsService $settings,
        private readonly SeoService $seo,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('/katha', name: 'app_story_index', methods: ['GET'])]
    public function index(Request $request, StoryFilterFactory $filters, NavigationService $navigation): Response
    {
        $filter = $filters->fromRequest($request);
        if (StoryListFilter::SORT_RELEVANCE === $filter->sort) {
            $filter->sort = StoryListFilter::SORT_LATEST;
        }
        $page = $this->page($request);
        $stories = $this->stories->paginatePublished($filter, $page, $this->settings->getInt(SettingsRegistry::STORIES_PER_PAGE));

        $seo = $this->seo->forListing($this->translator->trans('common.all_stories'), $this->translator->trans('story.index.meta_description'), 'app_story_index', [], $page);
        if ($filter->hasActiveFilters()) {
            // Filtered combinations are not separate landing pages.
            $seo->index = false;
        }

        return $this->render('story/index.html.twig', [
            'stories' => $stories,
            'filter' => $filter,
            'facets' => [
                'category' => $navigation->terms('category'),
                'devta' => $navigation->terms('deity'),
                'granth' => $navigation->terms('scripture'),
            ],
            'seo' => $seo,
        ]);
    }

    #[Route('/katha/{slug}', name: 'app_story_show', requirements: ['slug' => '[a-z0-9-]+'], methods: ['GET'])]
    public function show(
        string $slug,
        StorySlugHistoryRepository $slugHistory,
        BookmarkRepository $bookmarks,
        StoryProgressService $progress,
        StoryPageDataBuilder $pageData,
    ): Response {
        $story = $this->stories->findPublishedBySlug($slug);
        if (null === $story) {
            $old = $slugHistory->findOneBySlug($slug);
            if (null !== $old && $old->getStory()->isPublished()) {
                return $this->redirectToRoute('app_story_show', ['slug' => $old->getStory()->getSlug()], Response::HTTP_MOVED_PERMANENTLY);
            }
            throw $this->createNotFoundException('Story not found.');
        }

        $user = $this->currentUser();

        // HTML stays private (header shows the visitor's account state, the CSP nonce is per request);
        // the expensive parts are served from the application cache (StoryPageDataBuilder, navigation).
        return $this->render('story/show.html.twig', $pageData->build($story) + [
            'bookmarked' => null !== $user && null !== $bookmarks->findOneByUserAndStory($user, $story),
            'progress' => null !== $user ? $progress->get($user, $story) : null,
            'preview' => false,
            'seo' => $this->seo->forStory($story),
        ]);
    }
}
