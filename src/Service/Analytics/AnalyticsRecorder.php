<?php

declare(strict_types=1);

namespace App\Service\Analytics;

use App\Enum\MetricEvent;
use App\Repository\DailyStoryMetricRepository;
use App\Service\Settings\SettingsRegistry;
use App\Service\Settings\SettingsService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Contracts\Service\ResetInterface;

/**
 * First-party, privacy-friendly analytics. Events are aggregated in memory during a request and
 * written once per (story, day) with an upsert — never one row per event, never personal data.
 */
final class AnalyticsRecorder implements ResetInterface
{
    /** @var array<int, array<string, array<string, int>>> storyId => date => column => count */
    private array $buffer = [];

    public function __construct(
        private readonly DailyStoryMetricRepository $metrics,
        private readonly SettingsService $settings,
        private readonly ClockInterface $clock,
        #[Autowire(param: 'app.timezone')]
        private readonly string $timezone,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function record(int $storyId, MetricEvent $event, int $amount = 1): void
    {
        $this->recordColumn($storyId, $event->column(), $amount);
    }

    /** For counters that are not client events (e.g. verified donation amounts). */
    public function recordColumn(int $storyId, string $column, int $amount = 1): void
    {
        if ($amount <= 0 || $storyId <= 0) {
            return;
        }
        $date = $this->today();
        $this->buffer[$storyId][$date][$column] = ($this->buffer[$storyId][$date][$column] ?? 0) + $amount;
    }

    #[AsEventListener(event: KernelEvents::TERMINATE)]
    #[AsEventListener(event: ConsoleEvents::TERMINATE)]
    public function flush(): void
    {
        if ([] === $this->buffer) {
            return;
        }
        $buffer = $this->buffer;
        $this->buffer = [];

        try {
            if (!$this->settings->getBool(SettingsRegistry::ANALYTICS_ENABLED)) {
                return;
            }
            foreach ($buffer as $storyId => $days) {
                foreach ($days as $date => $columns) {
                    $this->metrics->increment($storyId, new \DateTimeImmutable($date), $columns);
                }
            }
        } catch (\Throwable $e) {
            // Analytics must never break the user experience.
            $this->logger->warning('Analytics flush failed: {error}', ['error' => $e->getMessage()]);
        }
    }

    public function reset(): void
    {
        $this->flush();
    }

    private function today(): string
    {
        return $this->clock->now()->setTimezone(new \DateTimeZone($this->timezone))->format('Y-m-d');
    }
}
