<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\DailyStoryMetricRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Aggregated per-story, per-day counters. Rows are upserted in batches
 * (INSERT ... ON DUPLICATE KEY UPDATE), never one row per event, and contain no personal data.
 * The date is the calendar date in the application timezone.
 */
#[ORM\Entity(repositoryClass: DailyStoryMetricRepository::class)]
#[ORM\Table(name: 'daily_story_metric')]
#[ORM\UniqueConstraint(name: 'uniq_metric_story_date', columns: ['story_id', 'metric_date'])]
#[ORM\Index(name: 'idx_metric_date', columns: ['metric_date'])]
class DailyStoryMetric
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Story $story;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $metricDate;

    #[ORM\Column(options: ['default' => 0])]
    private int $views = 0;

    /** Approximation: first view of the story per browser per day (client-side flag, no tracking id). */
    #[ORM\Column(options: ['default' => 0])]
    private int $uniqueViews = 0;

    #[ORM\Column(options: ['default' => 0])]
    private int $audioStarts = 0;

    #[ORM\Column(options: ['default' => 0])]
    private int $audioProgress25 = 0;

    #[ORM\Column(options: ['default' => 0])]
    private int $audioProgress50 = 0;

    #[ORM\Column(options: ['default' => 0])]
    private int $audioProgress75 = 0;

    #[ORM\Column(options: ['default' => 0])]
    private int $audioCompletions = 0;

    #[ORM\Column(options: ['default' => 0])]
    private int $bookmarks = 0;

    #[ORM\Column(options: ['default' => 0])]
    private int $collectionAdds = 0;

    #[ORM\Column(options: ['default' => 0])]
    private int $shares = 0;

    #[ORM\Column(options: ['default' => 0])]
    private int $donationsInitiated = 0;

    #[ORM\Column(options: ['default' => 0])]
    private int $donationsDeclared = 0;

    #[ORM\Column(options: ['default' => 0])]
    private int $donationsVerified = 0;

    /** Verified contribution amount (paise) for that day. */
    #[ORM\Column(type: Types::BIGINT, options: ['default' => 0])]
    private int|string $donationAmountPaise = 0;

    public function __construct(Story $story, \DateTimeImmutable $metricDate)
    {
        $this->story = $story;
        $this->metricDate = $metricDate;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getStory(): Story
    {
        return $this->story;
    }

    public function getMetricDate(): \DateTimeImmutable
    {
        return $this->metricDate;
    }

    public function getViews(): int
    {
        return $this->views;
    }

    public function getUniqueViews(): int
    {
        return $this->uniqueViews;
    }

    public function getAudioStarts(): int
    {
        return $this->audioStarts;
    }

    public function getAudioCompletions(): int
    {
        return $this->audioCompletions;
    }

    public function getBookmarks(): int
    {
        return $this->bookmarks;
    }

    public function getShares(): int
    {
        return $this->shares;
    }

    public function getDonationAmountPaise(): int
    {
        return (int) $this->donationAmountPaise;
    }
}
