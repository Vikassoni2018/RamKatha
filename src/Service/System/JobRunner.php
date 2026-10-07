<?php

declare(strict_types=1);

namespace App\Service\System;

use App\Entity\SystemHeartbeat;
use App\Repository\SystemHeartbeatRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Lock\LockFactory;

/**
 * Runs a periodic job under a Symfony Lock (no overlapping runs between cron and the scheduler
 * worker) and records a heartbeat so the System Status page can show "last run".
 */
final class JobRunner
{
    public const STATUS_OK = 'ok';
    public const STATUS_ERROR = 'error';
    public const STATUS_SKIPPED = 'skipped';

    public function __construct(
        private readonly LockFactory $lockFactory,
        private readonly EntityManagerInterface $em,
        private readonly SystemHeartbeatRepository $heartbeats,
        private readonly ClockInterface $clock,
        #[Autowire(service: 'monolog.logger.jobs')]
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param callable(): (string|null) $job returns an optional summary message
     *
     * @return array{status: string, message: ?string}
     */
    public function run(string $name, callable $job, int $lockTtl = 900): array
    {
        $lock = $this->lockFactory->createLock('job-'.$name, $lockTtl);
        if (!$lock->acquire()) {
            $this->logger->info('Job {name} skipped: already running.', ['name' => $name]);

            return ['status' => self::STATUS_SKIPPED, 'message' => 'Already running'];
        }

        $started = microtime(true);
        try {
            $message = $job();
            $this->record($name, self::STATUS_OK, $message, $started);

            return ['status' => self::STATUS_OK, 'message' => $message];
        } catch (\Throwable $e) {
            $this->logger->error('Job {name} failed: {error}', ['name' => $name, 'error' => $e->getMessage(), 'exception' => $e]);
            $this->record($name, self::STATUS_ERROR, $e->getMessage(), $started);

            return ['status' => self::STATUS_ERROR, 'message' => $e->getMessage()];
        } finally {
            $lock->release();
        }
    }

    public function record(string $name, string $status, ?string $message, float $startedAt): void
    {
        try {
            if (!$this->em->isOpen()) {
                return;
            }
            $heartbeat = $this->heartbeats->findOneBy(['name' => $name]) ?? new SystemHeartbeat($name);
            $heartbeat->record($status, $message, (int) round((microtime(true) - $startedAt) * 1000), $this->clock->now());
            $this->em->persist($heartbeat);
            $this->em->flush();
        } catch (\Throwable $e) {
            $this->logger->warning('Could not record heartbeat for {name}: {error}', ['name' => $name, 'error' => $e->getMessage()]);
        }
    }
}
