<?php

declare(strict_types=1);

namespace App\Service\Story;

use App\DTO\StoryListFilter;
use App\Entity\Story;
use App\Enum\AudioStatus;
use App\Enum\StoryStatus;
use App\Pagination\Paginator;
use App\Repository\StoryRepository;
use Doctrine\DBAL\Connection;
use Symfony\Component\Clock\ClockInterface;

/**
 * MySQL FULLTEXT search (no Elasticsearch/Meilisearch).
 *
 * Two FULLTEXT indexes are used: ft_story_title (title, subtitle, original_title) weighted ×3 and
 * ft_story_search (title, subtitle, excerpt, search_text) where search_text contains the plain
 * body plus scripture/deity/category/tag/series names — in Hindi and, where available, English
 * (see StoryContentService), so both languages find the same stories. Terms shorter than InnoDB's
 * minimum token size (3) fall back to LIKE. Filters reuse the composite/link-table indexes.
 */
final class StorySearchService
{
    public const MIN_TOKEN_LENGTH = 3;

    public function __construct(
        private readonly Connection $connection,
        private readonly StoryRepository $stories,
        private readonly ClockInterface $clock,
    ) {
    }

    /** @return Paginator<Story> */
    public function search(StoryListFilter $filter, int $page, int $perPage = 18): Paginator
    {
        $query = trim((string) $filter->query);
        if ('' === $query && !$filter->hasActiveFilters()) {
            return Paginator::empty($perPage);
        }

        $params = ['published' => StoryStatus::Published->value, 'now' => $this->clock->now()->format('Y-m-d H:i:s')];
        $where = ['s.status = :published', 's.published_at <= :now'];
        $score = '0';

        if ('' !== $query) {
            $boolean = $this->toBooleanQuery($query);
            $params['like'] = '%'.addcslashes($query, '%_\\').'%';
            if (null !== $boolean) {
                $params['ft'] = $boolean;
                $score = '(MATCH(s.title, s.subtitle, s.original_title) AGAINST (:ft IN BOOLEAN MODE) * 3 + MATCH(s.title, s.subtitle, s.excerpt, s.search_text) AGAINST (:ft IN BOOLEAN MODE))';
                $where[] = '(MATCH(s.title, s.subtitle, s.excerpt, s.search_text) AGAINST (:ft IN BOOLEAN MODE) OR s.title LIKE :like)';
            } else {
                $where[] = '(s.title LIKE :like OR s.excerpt LIKE :like OR s.search_text LIKE :like)';
            }
        }

        $this->applyFilters($filter, $where, $params);
        $whereSql = implode(' AND ', $where);

        $total = (int) $this->connection->fetchOne("SELECT COUNT(*) FROM story s WHERE {$whereSql}", $params);
        if (0 === $total) {
            return Paginator::fromResults([], 0, $page, $perPage);
        }

        $order = match ($filter->sort) {
            StoryListFilter::SORT_LATEST => 's.published_at DESC',
            StoryListFilter::SORT_POPULAR => 's.popularity_score DESC, s.published_at DESC',
            StoryListFilter::SORT_SHORTEST => 's.reading_time_minutes ASC, s.published_at DESC',
            StoryListFilter::SORT_OLDEST => 's.published_at ASC',
            default => 'score DESC, s.popularity_score DESC, s.published_at DESC',
        };
        $page = max(1, min($page, (int) ceil($total / $perPage)));
        $offset = ($page - 1) * $perPage;

        $ids = $this->connection->fetchFirstColumn(
            "SELECT s.id, {$score} AS score FROM story s WHERE {$whereSql} ORDER BY {$order}, s.id DESC LIMIT ".(int) $perPage.' OFFSET '.(int) $offset,
            $params,
        );

        return Paginator::fromResults($this->stories->findCardsByIds(array_map('intval', $ids)), $total, $page, $perPage);
    }

    /**
     * Lightweight suggestions for the search box (Hindi or English title matches).
     *
     * @return list<array{title: string, titleEn: ?string, slug: string}>
     */
    public function suggest(string $query, int $limit = 6): array
    {
        $query = trim($query);
        if (mb_strlen($query) < 2) {
            return [];
        }
        $params = [
            'published' => StoryStatus::Published->value,
            'now' => $this->clock->now()->format('Y-m-d H:i:s'),
            'prefix' => addcslashes($query, '%_\\').'%',
            'like' => '%'.addcslashes($query, '%_\\').'%',
        ];
        $boolean = $this->toBooleanQuery($query);
        $match = '';
        if (null !== $boolean) {
            $params['ft'] = $boolean;
            $match = ' OR MATCH(s.title, s.subtitle, s.original_title) AGAINST (:ft IN BOOLEAN MODE)';
        }

        return $this->connection->fetchAllAssociative(
            "SELECT s.title, s.title_en AS titleEn, s.slug FROM story s
             WHERE s.status = :published AND s.published_at <= :now AND (s.title LIKE :like OR s.title_en LIKE :like OR s.original_title LIKE :like{$match})
             ORDER BY (s.title LIKE :prefix OR s.title_en LIKE :prefix) DESC, s.popularity_score DESC
             LIMIT ".(int) $limit,
            $params,
        );
    }

    /**
     * Converts free text into a safe BOOLEAN MODE expression: every term required, prefix match.
     * Operator characters are stripped, so user input can never alter the query structure.
     */
    public function toBooleanQuery(string $query): ?string
    {
        $clean = preg_replace('/[+\-<>()~*"@\'\\\\]/u', ' ', $query) ?? '';
        $terms = preg_split('/[\s,।॥.!?;:]+/u', $clean, -1, \PREG_SPLIT_NO_EMPTY) ?: [];
        $parts = [];
        foreach (\array_slice(array_unique($terms), 0, 8) as $term) {
            if (mb_strlen($term) >= self::MIN_TOKEN_LENGTH) {
                $parts[] = '+'.$term.'*';
            }
        }

        return [] === $parts ? null : implode(' ', $parts);
    }

    /** @return list<string> terms to highlight in results */
    public function highlightTerms(?string $query): array
    {
        if (null === $query) {
            return [];
        }
        $terms = preg_split('/[\s,।॥.!?;:+\-<>()~*"@]+/u', $query, -1, \PREG_SPLIT_NO_EMPTY) ?: [];

        return array_values(array_filter(array_unique($terms), static fn (string $t) => mb_strlen($t) >= 2));
    }

    /**
     * @param list<string> $where
     * @param array<string, mixed> $params
     */
    private function applyFilters(StoryListFilter $filter, array &$where, array &$params): void
    {
        $links = [
            'category' => ['story_category', 'category_id', $filter->category],
            'deity' => ['story_deity', 'deity_id', $filter->deity],
            'tag' => ['story_tag', 'tag_id', $filter->tag],
            'festival' => ['story_festival', 'festival_id', $filter->festival],
        ];
        foreach ($links as $name => [$table, $column, $term]) {
            if (null !== $term) {
                $where[] = "EXISTS (SELECT 1 FROM {$table} l_{$name} WHERE l_{$name}.story_id = s.id AND l_{$name}.{$column} = :{$name})";
                $params[$name] = $term->getId();
            }
        }
        if (null !== $filter->scripture) {
            $where[] = 's.scripture_id = :scripture';
            $params['scripture'] = $filter->scripture->getId();
        }
        if (null !== $filter->series) {
            $where[] = 's.series_id = :series';
            $params['series'] = $filter->series->getId();
        }
        if (null !== $filter->sourceType) {
            $where[] = 's.source_type = :sourceType';
            $params['sourceType'] = $filter->sourceType->value;
        }
        if (true === $filter->hasAudio) {
            $where[] = 's.audio_status = :ready AND s.audio_enabled = 1';
            $params['ready'] = AudioStatus::Ready->value;
        } elseif (false === $filter->hasAudio) {
            $where[] = '(s.audio_status IS NULL OR s.audio_status <> :ready OR s.audio_enabled = 0)';
            $params['ready'] = AudioStatus::Ready->value;
        }
        [$min, $max] = $filter->durationRange();
        if (null !== $min) {
            $where[] = 'COALESCE(s.audio_duration_seconds, s.reading_time_minutes * 60) >= :minDuration';
            $params['minDuration'] = $min;
        }
        if (null !== $max) {
            $where[] = 'COALESCE(s.audio_duration_seconds, s.reading_time_minutes * 60) <= :maxDuration';
            $params['maxDuration'] = $max;
        }
    }
}
