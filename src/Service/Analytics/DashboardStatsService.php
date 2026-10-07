<?php

declare(strict_types=1);

namespace App\Service\Analytics;

use App\Enum\AudioStatus;
use App\Enum\DonationStatus;
use App\Enum\SocialPostStatus;
use App\Repository\AdminNotificationRepository;
use App\Repository\BookmarkRepository;
use App\Repository\DailyStoryMetricRepository;
use App\Repository\DonationRepository;
use App\Repository\SocialPostRepository;
use App\Repository\StoryProgressRepository;
use App\Repository\StoryRepository;
use App\Repository\UserCollectionRepository;
use App\Repository\UserRepository;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/** Aggregated numbers for the admin dashboard and analytics page (internal data only). */
final class DashboardStatsService
{
    public function __construct(
        private readonly StoryRepository $stories,
        private readonly UserRepository $users,
        private readonly BookmarkRepository $bookmarks,
        private readonly UserCollectionRepository $collections,
        private readonly StoryProgressRepository $progress,
        private readonly DonationRepository $donations,
        private readonly SocialPostRepository $socialPosts,
        private readonly DailyStoryMetricRepository $metrics,
        private readonly AdminNotificationRepository $notifications,
        private readonly ClockInterface $clock,
        #[Autowire(param: 'app.timezone')]
        private readonly string $timezone,
    ) {
    }

    /** @return array<string, mixed> */
    public function dashboard(): array
    {
        $statusCounts = $this->stories->countByStatus();
        $audioCounts = $this->stories->countByAudioStatus();
        $today = $this->today();
        $from = $today->modify('-29 days');
        $donationTotals = $this->donations->totalsByStatus();
        $socialCounts = $this->socialPosts->countByStatus();

        return [
            'stories' => [
                'total' => array_sum($statusCounts),
                'published' => $statusCounts['published'] ?? 0,
                'drafts' => $statusCounts['draft'] ?? 0,
                'inReview' => ($statusCounts['under_review'] ?? 0) + ($statusCounts['source_verified'] ?? 0),
                'scheduled' => $statusCounts['scheduled'] ?? 0,
                'byStatus' => $statusCounts,
            ],
            'audio' => [
                'pending' => ($audioCounts[AudioStatus::Pending->value] ?? 0) + ($audioCounts[AudioStatus::Queued->value] ?? 0) + ($audioCounts[AudioStatus::Processing->value] ?? 0),
                'ready' => $audioCounts[AudioStatus::Ready->value] ?? 0,
                'failed' => $audioCounts[AudioStatus::Failed->value] ?? 0,
            ],
            'users' => [
                'total' => $this->users->countAll(),
                'new7' => $this->users->countCreatedSince($this->clock->now()->modify('-7 days')),
            ],
            'engagement30' => $this->metrics->totalsBetween($from, $today),
            'engagementLifetime' => [
                'bookmarks' => $this->bookmarks->countAll(),
                'collections' => $this->collections->countAll(),
                'completedListens' => $this->progress->countCompleted(),
            ],
            'donations' => [
                'verifiedCount' => $donationTotals[DonationStatus::Verified->value]['count'] ?? 0,
                'verifiedAmount' => $donationTotals[DonationStatus::Verified->value]['amount'] ?? 0,
                'pendingCount' => $donationTotals[DonationStatus::Declared->value]['count'] ?? 0,
                'pendingAmount' => $donationTotals[DonationStatus::Declared->value]['amount'] ?? 0,
            ],
            'social' => [
                'published' => $socialCounts[SocialPostStatus::Published->value] ?? 0,
                'failed' => $socialCounts[SocialPostStatus::Failed->value] ?? 0,
                'queued' => ($socialCounts[SocialPostStatus::Queued->value] ?? 0) + ($socialCounts[SocialPostStatus::Publishing->value] ?? 0),
            ],
            'series' => $this->metrics->dailySeries($from, $today),
            'failures' => $this->notifications->findUnreadErrors(6),
            'scheduled' => $this->stories->findUpcomingScheduled(5),
        ];
    }

    /** @return array{from: \DateTimeImmutable, to: \DateTimeImmutable, totals: array<string, int>, series: list<array<string, mixed>>, top: list<array<string, mixed>>} */
    public function analytics(\DateTimeImmutable $from, \DateTimeImmutable $to, string $orderBy = 'views'): array
    {
        return [
            'from' => $from,
            'to' => $to,
            'totals' => $this->metrics->totalsBetween($from, $to),
            'series' => $this->metrics->dailySeries($from, $to),
            'top' => $this->metrics->topStories($from, $to, 25, $orderBy),
        ];
    }

    public function today(): \DateTimeImmutable
    {
        return $this->clock->now()->setTimezone(new \DateTimeZone($this->timezone))->setTime(0, 0);
    }
}
