<?php

declare(strict_types=1);

namespace App\Service\Story;

use App\DTO\StoryListFilter;
use App\Entity\Taxonomy\Category;
use App\Entity\Taxonomy\Deity;
use App\Entity\Taxonomy\Festival;
use App\Entity\Taxonomy\Scripture;
use App\Entity\Taxonomy\Series;
use App\Entity\Taxonomy\Tag;
use App\Enum\SourceType;
use Symfony\Component\HttpFoundation\Request;

/** Builds a StoryListFilter from query parameters; unknown or invalid values are ignored. */
final class StoryFilterFactory
{
    /** query parameter => taxonomy key */
    public const PARAMS = [
        'category' => 'category',
        'devta' => 'deity',
        'granth' => 'scripture',
        'series' => 'series',
        'festival' => 'festival',
        'tag' => 'tag',
    ];

    public function __construct(private readonly TaxonomyRegistry $taxonomies)
    {
    }

    public function fromRequest(Request $request, ?StoryListFilter $filter = null): StoryListFilter
    {
        $filter ??= new StoryListFilter();
        $query = $request->query;

        $q = trim((string) $query->get('q', ''));
        $filter->query = '' !== $q ? mb_substr($q, 0, 120) : null;

        foreach (self::PARAMS as $param => $key) {
            $slug = $query->get($param);
            if (!\is_string($slug) || '' === $slug || !preg_match('/^[a-z0-9-]{1,160}$/', $slug)) {
                continue;
            }
            $term = $this->taxonomies->repository($key)->findOneActiveBySlug($slug);
            if (null === $term) {
                continue;
            }
            match (true) {
                $term instanceof Category => $filter->category = $term,
                $term instanceof Deity => $filter->deity = $term,
                $term instanceof Scripture => $filter->scripture = $term,
                $term instanceof Series => $filter->series = $term,
                $term instanceof Festival => $filter->festival = $term,
                $term instanceof Tag => $filter->tag = $term,
                default => null,
            };
        }

        $type = $query->get('type');
        $filter->sourceType = \is_string($type) ? SourceType::tryFrom($type) : null;

        $audio = $query->get('audio');
        $filter->hasAudio = match ($audio) {
            '1' => true,
            '0' => false,
            default => null,
        };

        $duration = $query->get('duration');
        $filter->duration = \is_string($duration) && isset(StoryListFilter::DURATIONS[$duration]) ? $duration : null;

        $sort = $query->get('sort');
        $allowed = array_merge(array_keys(StoryListFilter::SORTS), [StoryListFilter::SORT_RELEVANCE]);
        if (\is_string($sort) && \in_array($sort, $allowed, true)) {
            $filter->sort = $sort;
        } elseif (null !== $filter->query) {
            $filter->sort = StoryListFilter::SORT_RELEVANCE;
        }

        return $filter;
    }
}
