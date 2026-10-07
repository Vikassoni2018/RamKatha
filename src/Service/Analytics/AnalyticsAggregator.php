<?php

declare(strict_types=1);

namespace App\Service\Analytics;

use App\Enum\DonationStatus;
use App\Service\Cache\ContentCacheInvalidator;
use Doctrine\DBAL\Connection;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Periodically refreshes denormalised story counters and the popularity score with a handful of
 * set-based UPDATE ... JOIN statements (cheap, no per-story queries).
 *
 * Popularity (recent activity, not lifetime views):
 *   score = Σ over the last 14 days of
 *           (views + 2·audio_starts + 4·completions + 5·bookmarks + 3·collection_adds + 3·shares)
 *           × 0.5^(age_in_days / 7)                       (7-day half-life)
 */
final class AnalyticsAggregator
{
    public const POPULARITY_WINDOW_DAYS = 14;
    public const HALF_LIFE_DAYS = 7;

    public function __construct(
        private readonly Connection $connection,
        private readonly ContentCacheInvalidator $cache,
        private readonly ClockInterface $clock,
        #[Autowire(param: 'app.timezone')]
        private readonly string $timezone,
    ) {
    }

    /** @return array<string, int> affected rows per step */
    public function aggregate(): array
    {
        $today = $this->clock->now()->setTimezone(new \DateTimeZone($this->timezone));
        $since = $today->modify('-'.self::POPULARITY_WINDOW_DAYS.' days')->format('Y-m-d');
        $result = [];

        $result['lifetime'] = $this->connection->executeStatement(
            'UPDATE story s
             LEFT JOIN (SELECT story_id, SUM(views) v, SUM(audio_starts) a, SUM(audio_completions) c, SUM(shares) sh
                        FROM daily_story_metric GROUP BY story_id) m ON m.story_id = s.id
             SET s.view_count = COALESCE(m.v, 0), s.listen_count = COALESCE(m.a, 0),
                 s.completion_count = COALESCE(m.c, 0), s.share_count = COALESCE(m.sh, 0)',
        );

        $result['bookmarks'] = $this->connection->executeStatement(
            'UPDATE story s
             LEFT JOIN (SELECT story_id, COUNT(*) total FROM bookmark GROUP BY story_id) b ON b.story_id = s.id
             SET s.bookmark_count = COALESCE(b.total, 0)',
        );

        $result['donations'] = $this->connection->executeStatement(
            'UPDATE story s
             LEFT JOIN (SELECT story_id, COUNT(*) total, SUM(amount_paise) amount FROM donation
                        WHERE status = :verified AND story_id IS NOT NULL GROUP BY story_id) d ON d.story_id = s.id
             SET s.donation_count = COALESCE(d.total, 0), s.donation_amount_paise = COALESCE(d.amount, 0)',
            ['verified' => DonationStatus::Verified->value],
        );

        $result['popularity'] = $this->connection->executeStatement(
            'UPDATE story s
             LEFT JOIN (
                SELECT story_id,
                       SUM((views + 2 * audio_starts + 4 * audio_completions + 5 * bookmarks + 3 * collection_adds + 3 * shares)
                           * POW(0.5, DATEDIFF(:today, metric_date) / :halfLife)) score
                FROM daily_story_metric WHERE metric_date >= :since GROUP BY story_id
             ) m ON m.story_id = s.id
             SET s.popularity_score = COALESCE(ROUND(m.score), 0)',
            ['today' => $today->format('Y-m-d'), 'since' => $since, 'halfLife' => self::HALF_LIFE_DAYS],
        );

        $this->cache->stories();

        return $result;
    }
}
