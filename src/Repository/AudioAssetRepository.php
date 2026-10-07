<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AudioAsset;
use App\Entity\Story;
use App\Enum\AudioStatus;
use App\Pagination\Paginator;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<AudioAsset>
 */
class AudioAssetRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AudioAsset::class);
    }

    public function findOneByUuid(Uuid $uuid): ?AudioAsset
    {
        return $this->createQueryBuilder('a')
            ->join('a.story', 's')->addSelect('s')
            ->andWhere('a.uuid = :uuid')
            ->setParameter('uuid', $uuid, 'uuid')
            ->getQuery()->getOneOrNullResult();
    }

    /** @return list<AudioAsset> */
    public function findForStory(Story $story, int $limit = 10): array
    {
        return $this->createQueryBuilder('a')
            ->leftJoin('a.requestedBy', 'u')->addSelect('u')
            ->andWhere('a.story = :story')
            ->setParameter('story', $story)
            ->orderBy('a.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()->getResult();
    }

    /** An asset that is already queued/processing for the story (prevents duplicate jobs). */
    public function findInProgressForStory(Story $story): ?AudioAsset
    {
        return $this->createQueryBuilder('a')
            ->andWhere('a.story = :story')
            ->andWhere('a.status IN (:statuses)')
            ->setParameter('story', $story)
            ->setParameter('statuses', [AudioStatus::Pending, AudioStatus::Queued, AudioStatus::Processing])
            ->orderBy('a.createdAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()->getOneOrNullResult();
    }

    /** @return Paginator<AudioAsset> */
    public function searchPaginated(?AudioStatus $status, int $page, int $perPage = 25): Paginator
    {
        $qb = $this->createQueryBuilder('a')
            ->join('a.story', 's')->addSelect('s')
            ->leftJoin('a.requestedBy', 'u')->addSelect('u')
            ->orderBy('a.updatedAt', 'DESC');
        if (null !== $status) {
            $qb->andWhere('a.status = :status')->setParameter('status', $status);
        }

        return Paginator::fromQuery($qb, $page, $perPage);
    }

    /** @return list<AudioAsset> queue view: pending, queued, processing (oldest first) */
    public function findQueue(int $limit = 100): array
    {
        return $this->createQueryBuilder('a')
            ->join('a.story', 's')->addSelect('s')
            ->andWhere('a.status IN (:statuses)')
            ->setParameter('statuses', [AudioStatus::Pending, AudioStatus::Queued, AudioStatus::Processing])
            ->orderBy('a.createdAt', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()->getResult();
    }

    /** @return list<AudioAsset> failed assets that may be retried automatically */
    public function findRetryable(int $maxAttempts, \DateTimeImmutable $notAttemptedSince, int $limit = 10): array
    {
        return $this->createQueryBuilder('a')
            ->join('a.story', 's')->addSelect('s')
            ->andWhere('a.status = :failed')
            ->andWhere('a.attempts < :max')
            ->andWhere('a.lastAttemptAt IS NULL OR a.lastAttemptAt <= :since')
            ->setParameter('failed', AudioStatus::Failed)
            ->setParameter('max', $maxAttempts)
            ->setParameter('since', $notAttemptedSince)
            ->orderBy('a.lastAttemptAt', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()->getResult();
    }

    /** @return list<AudioAsset> assets stuck in "processing" (worker crashed) */
    public function findStaleProcessing(\DateTimeImmutable $startedBefore): array
    {
        return $this->createQueryBuilder('a')
            ->andWhere('a.status = :processing')
            ->andWhere('a.generationStartedAt < :before')
            ->setParameter('processing', AudioStatus::Processing)
            ->setParameter('before', $startedBefore)
            ->getQuery()->getResult();
    }

    /** @return list<AudioAsset> queued assets older than the given time (worker not running?) */
    public function findQueuedBefore(\DateTimeImmutable $before, int $limit = 10): array
    {
        return $this->createQueryBuilder('a')
            ->andWhere('a.status IN (:statuses)')
            ->andWhere('a.updatedAt < :before')
            ->setParameter('statuses', [AudioStatus::Pending, AudioStatus::Queued])
            ->setParameter('before', $before)
            ->orderBy('a.createdAt', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()->getResult();
    }

    /**
     * Atomically claims an asset for processing. Returns false if another worker already did.
     * This makes the generation job idempotent even with several consumers.
     */
    public function claimForProcessing(AudioAsset $asset, \DateTimeImmutable $staleBefore): bool
    {
        $affected = $this->getEntityManager()->getConnection()->executeStatement(
            'UPDATE audio_asset SET status = :processing, updated_at = :now
             WHERE id = :id AND (status IN (:claimable) OR (status = :processing AND generation_started_at < :stale))',
            [
                'processing' => AudioStatus::Processing->value,
                'now' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
                'id' => $asset->getId(),
                'claimable' => [AudioStatus::Pending->value, AudioStatus::Queued->value, AudioStatus::Failed->value],
                'stale' => $staleBefore->format('Y-m-d H:i:s'),
            ],
            ['claimable' => \Doctrine\DBAL\ArrayParameterType::STRING],
        );

        return 1 === $affected;
    }

    /** @return list<string> storage keys of every asset (used by orphan cleanup) */
    public function findAllStorageKeys(): array
    {
        return array_column($this->createQueryBuilder('a')
            ->select('a.storageKey')
            ->andWhere('a.storageKey IS NOT NULL')
            ->getQuery()->getScalarResult(), 'storageKey');
    }

    public function countByStatus(AudioStatus $status): int
    {
        return $this->count(['status' => $status]);
    }
}
