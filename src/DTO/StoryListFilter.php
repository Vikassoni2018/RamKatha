<?php

declare(strict_types=1);

namespace App\DTO;

use App\Entity\Taxonomy\Category;
use App\Entity\Taxonomy\Deity;
use App\Entity\Taxonomy\Festival;
use App\Entity\Taxonomy\Scripture;
use App\Entity\Taxonomy\Series;
use App\Entity\Taxonomy\Tag;
use App\Enum\SourceType;

/**
 * Public listing/search filters. Built from the query string by StoryFilterFactory.
 */
final class StoryListFilter
{
    public const SORT_LATEST = 'latest';
    public const SORT_POPULAR = 'popular';
    public const SORT_OLDEST = 'oldest';
    public const SORT_SHORTEST = 'shortest';
    public const SORT_RELEVANCE = 'relevance';
    public const SORT_SERIES = 'series';

    public const DURATION_UNDER_10 = 'lt10';
    public const DURATION_10_TO_20 = '10to20';
    public const DURATION_OVER_20 = 'gt20';

    /** Sort option => translation key of its label (messages domain; translated in the template). */
    public const SORTS = [
        self::SORT_LATEST => 'filters.sort.latest',
        self::SORT_POPULAR => 'filters.sort.popular',
        self::SORT_SHORTEST => 'filters.sort.shortest',
        self::SORT_OLDEST => 'filters.sort.oldest',
    ];

    /** Duration bucket => translation key of its label (messages domain; translated in the template). */
    public const DURATIONS = [
        self::DURATION_UNDER_10 => 'filters.duration.under_10',
        self::DURATION_10_TO_20 => 'filters.duration.between_10_20',
        self::DURATION_OVER_20 => 'filters.duration.over_20',
    ];

    public ?string $query = null;
    public ?Category $category = null;
    public ?Deity $deity = null;
    public ?Scripture $scripture = null;
    public ?Series $series = null;
    public ?Festival $festival = null;
    public ?Tag $tag = null;
    public ?SourceType $sourceType = null;
    public ?bool $hasAudio = null;
    public ?string $duration = null;
    public string $sort = self::SORT_LATEST;

    /** @return array{0: int|null, 1: int|null} min/max seconds for the duration bucket */
    public function durationRange(): array
    {
        return match ($this->duration) {
            self::DURATION_UNDER_10 => [null, 600],
            self::DURATION_10_TO_20 => [601, 1200],
            self::DURATION_OVER_20 => [1201, null],
            default => [null, null],
        };
    }

    public function hasActiveFilters(): bool
    {
        return null !== $this->category || null !== $this->deity || null !== $this->scripture
            || null !== $this->series || null !== $this->festival || null !== $this->tag
            || null !== $this->sourceType || null !== $this->hasAudio || null !== $this->duration;
    }
}
