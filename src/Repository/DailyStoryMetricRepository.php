<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\DailyStoryMetric;
use App\Enum\MetricEvent;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ParameterType;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<DailyStoryMetric>
 */
class DailyStoryMetricRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DailyStoryMetric::class);
    }

    /**
     * Atomically adds counters for one story/day: a single INSERT ... ON DUPLICATE KEY UPDATE.
     * Column names come exclusively from the MetricEvent enum (never from user input).
     *
     * @param array<string, int> $increments column => amount
     */
    public function increment(int $storyId, \DateTimeImmutable $date, array $increments): void
    {
        $allowed = array_map(static fn (MetricEvent $e) => $e->column(), MetricEvent::cases());
        $allowed[] = 'donations_verified';
        $allowed[] = 'donation_amount_paise';
        $increments = array_filter($increments, static fn ($v, $k) => $v > 0 && \in_array($k, $allowed, true), \ARRAY_FILTER_USE_BOTH);
        if ([] === $increments) {
            return;
        }

        $columns = array_keys($increments);
        $insertColumns = implode(', ', $columns);
        $placeholders = implode(', ', array_map(static fn ($c) => ':'.$c, $columns));
        $updates = implode(', ', array_map(static fn ($c) => $c.' = '.$c.' + VALUES('.$c.')', $columns));

        $params = ['story' => $storyId, 'metric_date' => $date->format('Y-m-d')];
        $types = ['story' => ParameterType::INTEGER];
        foreach ($increments as $column => $value) {
            $params[$column] = $value;
            $types[$column] = ParameterType::INTEGER;
        }

        $this->getEntityManager()->getConnection()->executeStatement(
            "INSERT INTO daily_story_metric (story_id, metric_date, {$insertColumns}) VALUES (:story, :metric_date, {$placeholders})
             ON DUPLICATE KEY UPDATE {$updates}",
            $params,
            $types,
        );
    }

    /**
     * Totals across all stories for a date range (inclusive).
     *
     * @return array<string, int>
     */
    public function totalsBetween(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $row = $this->getEntityManager()->getConnection()->fetchAssociative(
            'SELECT COALESCE(SUM(views),0) views, COALESCE(SUM(unique_views),0) unique_views, COALESCE(SUM(audio_starts),0) audio_starts,
                    COALESCE(SUM(audio_completions),0) audio_completions, COALESCE(SUM(bookmarks),0) bookmarks,
                    COALESCE(SUM(collection_adds),0) collection_adds, COALESCE(SUM(shares),0) shares,
                    COALESCE(SUM(donations_initiated),0) donations_initiated, COALESCE(SUM(donations_declared),0) donations_declared
             FROM daily_story_metric WHERE metric_date BETWEEN :from AND :to',
            ['from' => $from->format('Y-m-d'), 'to' => $to->format('Y-m-d')],
        );

        return array_map('intval', $row ?: []);
    }

    /** @return list<array{date: string, views: int, audio_starts: int, audio_completions: int}> one row per day */
    public function dailySeries(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $rows = $this->getEntityManager()->getConnection()->fetchAllAssociative(
            'SELECT metric_date AS date, SUM(views) views, SUM(audio_starts) audio_starts, SUM(audio_completions) audio_completions, SUM(shares) shares
             FROM daily_story_metric WHERE metric_date BETWEEN :from AND :to GROUP BY metric_date ORDER BY metric_date',
            ['from' => $from->format('Y-m-d'), 'to' => $to->format('Y-m-d')],
        );
        $indexed = [];
        foreach ($rows as $row) {
            $indexed[$row['date']] = $row;
        }

        // Fill missing days with zeros so charts have a continuous axis.
        $series = [];
        for ($day = $from; $day <= $to; $day = $day->modify('+1 day')) {
            $key = $day->format('Y-m-d');
            $series[] = [
                'date' => $key,
                'views' => (int) ($indexed[$key]['views'] ?? 0),
                'audio_starts' => (int) ($indexed[$key]['audio_starts'] ?? 0),
                'audio_completions' => (int) ($indexed[$key]['audio_completions'] ?? 0),
                'shares' => (int) ($indexed[$key]['shares'] ?? 0),
            ];
        }

        return $series;
    }

    /** @return list<array<string, mixed>> top stories by views for the range */
    public function topStories(\DateTimeImmutable $from, \DateTimeImmutable $to, int $limit = 20, string $orderBy = 'views'): array
    {
        $orderColumn = \in_array($orderBy, ['views', 'audio_starts', 'audio_completions', 'shares', 'bookmarks'], true) ? $orderBy : 'views';

        return $this->getEntityManager()->getConnection()->fetchAllAssociative(
            "SELECT s.id, s.title, s.slug, SUM(m.views) views, SUM(m.unique_views) unique_views, SUM(m.audio_starts) audio_starts,
                    SUM(m.audio_completions) audio_completions, SUM(m.bookmarks) bookmarks, SUM(m.shares) shares,
                    SUM(m.donations_declared) donations_declared
             FROM daily_story_metric m JOIN story s ON s.id = m.story_id
             WHERE m.metric_date BETWEEN :from AND :to
             GROUP BY s.id, s.title, s.slug
             ORDER BY {$orderColumn} DESC
             LIMIT ".(int) $limit,
            ['from' => $from->format('Y-m-d'), 'to' => $to->format('Y-m-d')],
        );
    }

    /**
     * Lifetime sums per story (for denormalised counters on Story).
     *
     * @return iterable<array<string, mixed>>
     */
    public function lifetimeTotalsPerStory(): iterable
    {
        return $this->getEntityManager()->getConnection()->iterateAssociative(
            'SELECT story_id, SUM(views) views, SUM(audio_starts) audio_starts, SUM(audio_completions) audio_completions, SUM(shares) shares
             FROM daily_story_metric GROUP BY story_id',
        );
    }

    /**
     * Recency-weighted engagement rows for the last N days.
     *
     * @return iterable<array<string, mixed>>
     */
    public function recentRows(\DateTimeImmutable $since): iterable
    {
        return $this->getEntityManager()->getConnection()->iterateAssociative(
            'SELECT story_id, metric_date, views, audio_starts, audio_completions, bookmarks, shares, collection_adds
             FROM daily_story_metric WHERE metric_date >= :since',
            ['since' => $since->format('Y-m-d')],
        );
    }
}
