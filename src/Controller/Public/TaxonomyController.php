<?php

declare(strict_types=1);

namespace App\Controller\Public;

use App\Controller\AppController;
use App\DTO\StoryListFilter;
use App\Entity\Taxonomy\Category;
use App\Entity\Taxonomy\Deity;
use App\Entity\Taxonomy\Festival;
use App\Entity\Taxonomy\Scripture;
use App\Entity\Taxonomy\Series;
use App\Entity\Taxonomy\Tag;
use App\Repository\StoryRepository;
use App\Service\Seo\SeoService;
use App\Service\Settings\SettingsRegistry;
use App\Service\Settings\SettingsService;
use App\Service\Story\NavigationService;
use App\Service\Story\StoryFilterFactory;
use App\Service\Story\TaxonomyRegistry;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * One controller for all browsable taxonomies:
 *   /category, /devta, /granth, /series, /festival  (index)
 *   /category/{slug}, /devta/{slug}, /granth/{slug}, /series/{slug}, /festival/{slug}, /tag/{slug}
 */
final class TaxonomyController extends AppController
{
    public function __construct(
        private readonly TaxonomyRegistry $taxonomies,
        private readonly SeoService $seo,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('/{segment}', name: 'app_taxonomy_index', requirements: ['segment' => 'category|devta|granth|series|festival'], methods: ['GET'])]
    public function index(string $segment, NavigationService $navigation): Response
    {
        $key = (string) $this->taxonomies->keyForSegment($segment);
        $terms = $this->taxonomies->repository($key)->findActiveOrdered();
        $counts = [];
        foreach ($navigation->terms($key, false) as $row) {
            $counts[$row['slug']] = $row['total'];
        }

        $plural = $this->taxonomies->plural($key);
        // Lower-cased for the English sentence ("Find stories by deities"); Devanagari has no case.
        $description = $this->translator->trans('browse.index.meta_description', ['%taxonomy%' => mb_strtolower($plural)]);

        return $this->render('taxonomy/index.html.twig', [
            'key' => $key,
            'segment' => $segment,
            'terms' => array_values(array_filter($terms, static fn ($t) => ($counts[$t->getSlug()] ?? 0) > 0)),
            'counts' => $counts,
            'seo' => $this->seo->forListing($plural, $description, 'app_taxonomy_index', ['segment' => $segment]),
        ]);
    }

    #[Route('/{segment}/{slug}', name: 'app_taxonomy_show', requirements: ['segment' => 'category|devta|granth|series|festival|tag', 'slug' => '[a-z0-9-]+'], methods: ['GET'])]
    public function show(string $segment, string $slug, Request $request, StoryRepository $stories, StoryFilterFactory $filters, SettingsService $settings): Response
    {
        $key = (string) $this->taxonomies->keyForSegment($segment);
        $term = $this->taxonomies->repository($key)->findOneActiveBySlug($slug) ?? throw $this->createNotFoundException();

        $filter = $filters->fromRequest($request);
        match (true) {
            $term instanceof Category => $filter->category = $term,
            $term instanceof Deity => $filter->deity = $term,
            $term instanceof Scripture => $filter->scripture = $term,
            $term instanceof Series => $filter->series = $term,
            $term instanceof Festival => $filter->festival = $term,
            $term instanceof Tag => $filter->tag = $term,
            default => null,
        };
        if ($term instanceof Series && null === $request->query->get('sort')) {
            $filter->sort = StoryListFilter::SORT_SERIES;
        } elseif (StoryListFilter::SORT_RELEVANCE === $filter->sort) {
            $filter->sort = StoryListFilter::SORT_LATEST;
        }

        $page = $this->page($request);

        return $this->render('taxonomy/show.html.twig', [
            'key' => $key,
            'segment' => $segment,
            'term' => $term,
            'stories' => $stories->paginatePublished($filter, $page, $settings->getInt(SettingsRegistry::STORIES_PER_PAGE)),
            'filter' => $filter,
            'seo' => $this->seo->forTaxonomy($key, $term, $page),
        ]);
    }
}
