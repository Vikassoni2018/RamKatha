<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Donation;
use App\Enum\DonationStatus;
use App\Pagination\Paginator;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<Donation>
 */
class DonationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Donation::class);
    }

    public function findOneByUuid(Uuid $uuid): ?Donation
    {
        return $this->createQueryBuilder('d')
            ->leftJoin('d.story', 's')->addSelect('s')
            ->andWhere('d.uuid = :uuid')
            ->setParameter('uuid', $uuid, 'uuid')
            ->getQuery()->getOneOrNullResult();
    }

    public function referenceCodeExists(string $code): bool
    {
        return $this->count(['referenceCode' => $code]) > 0;
    }

    /**
     * @param array{q?: ?string, status?: ?DonationStatus, from?: ?\DateTimeImmutable, to?: ?\DateTimeImmutable, story?: ?int} $filters
     */
    public function createFilteredQueryBuilder(array $filters): QueryBuilder
    {
        $qb = $this->createQueryBuilder('d')
            ->leftJoin('d.story', 's')->addSelect('s')
            ->leftJoin('d.user', 'u')->addSelect('u')
            ->orderBy('d.createdAt', 'DESC');

        if (!empty($filters['q'])) {
            $qb->andWhere('d.referenceCode LIKE :q OR d.upiTransactionReference LIKE :q OR d.donorName LIKE :q OR d.email LIKE :q')
                ->setParameter('q', '%'.addcslashes(trim($filters['q']), '%_').'%');
        }
        if (!empty($filters['status'])) {
            $qb->andWhere('d.status = :status')->setParameter('status', $filters['status']);
        }
        if (!empty($filters['from'])) {
            $qb->andWhere('d.createdAt >= :from')->setParameter('from', $filters['from']);
        }
        if (!empty($filters['to'])) {
            $qb->andWhere('d.createdAt < :to')->setParameter('to', $filters['to']);
        }
        if (!empty($filters['story'])) {
            $qb->andWhere('s.id = :story')->setParameter('story', $filters['story']);
        }

        return $qb;
    }

    /** @return Paginator<Donation> */
    public function searchPaginated(array $filters, int $page, int $perPage = 25): Paginator
    {
        return Paginator::fromQuery($this->createFilteredQueryBuilder($filters), $page, $perPage);
    }

    /** @return array{count: int, amount: int} totals for the filter (amount in paise) */
    public function totals(array $filters): array
    {
        $row = $this->createFilteredQueryBuilder($filters)
            ->select('COUNT(d.id) AS total, COALESCE(SUM(d.amountPaise), 0) AS amount')
            ->resetDQLPart('orderBy')
            ->getQuery()->getSingleResult();

        return ['count' => (int) $row['total'], 'amount' => (int) $row['amount']];
    }

    /** @return array<string, array{count: int, amount: int}> status => totals */
    public function totalsByStatus(?\DateTimeImmutable $since = null): array
    {
        $qb = $this->createQueryBuilder('d')
            ->select('d.status AS status, COUNT(d.id) AS total, COALESCE(SUM(d.amountPaise), 0) AS amount')
            ->groupBy('d.status');
        if (null !== $since) {
            $qb->andWhere('d.createdAt >= :since')->setParameter('since', $since);
        }
        $result = [];
        foreach ($qb->getQuery()->getArrayResult() as $row) {
            $status = $row['status'] instanceof DonationStatus ? $row['status']->value : (string) $row['status'];
            $result[$status] = ['count' => (int) $row['total'], 'amount' => (int) $row['amount']];
        }

        return $result;
    }

    /** @return list<array{storyId: int, title: string, count: int, amount: int}> verified totals per story */
    public function verifiedTotalsPerStory(int $limit = 20): array
    {
        $rows = $this->createQueryBuilder('d')
            ->select('s.id AS storyId, s.title AS title, COUNT(d.id) AS total, SUM(d.amountPaise) AS amount')
            ->join('d.story', 's')
            ->andWhere('d.status = :verified')
            ->setParameter('verified', DonationStatus::Verified)
            ->groupBy('s.id')
            ->orderBy('amount', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()->getArrayResult();

        return array_map(static fn (array $r) => ['storyId' => (int) $r['storyId'], 'title' => $r['title'], 'count' => (int) $r['total'], 'amount' => (int) $r['amount']], $rows);
    }

    /** Other donations that declared the same UTR (possible duplicate claim). */
    public function countWithReference(string $reference, ?int $excludeId = null): int
    {
        $qb = $this->createQueryBuilder('d')
            ->select('COUNT(d.id)')
            ->andWhere('d.upiTransactionReference = :ref')
            ->setParameter('ref', $reference);
        if (null !== $excludeId) {
            $qb->andWhere('d.id != :id')->setParameter('id', $excludeId);
        }

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /** @return array<int, array{count: int, amount: int}> storyId => verified totals (aggregation job) */
    public function verifiedTotalsByStoryId(): array
    {
        $rows = $this->createQueryBuilder('d')
            ->select('IDENTITY(d.story) AS storyId, COUNT(d.id) AS total, SUM(d.amountPaise) AS amount')
            ->andWhere('d.status = :verified')
            ->andWhere('d.story IS NOT NULL')
            ->setParameter('verified', DonationStatus::Verified)
            ->groupBy('d.story')
            ->getQuery()->getScalarResult();
        $result = [];
        foreach ($rows as $row) {
            $result[(int) $row['storyId']] = ['count' => (int) $row['total'], 'amount' => (int) $row['amount']];
        }

        return $result;
    }

    public function deleteStaleInitiated(\DateTimeImmutable $before): int
    {
        // Initiated records carry no payment proof; old ones are noise and safe to purge.
        return $this->createQueryBuilder('d')
            ->delete()
            ->andWhere('d.status = :initiated')
            ->andWhere('d.createdAt < :before')
            ->andWhere('d.upiTransactionReference IS NULL')
            ->setParameter('initiated', DonationStatus::Initiated)
            ->setParameter('before', $before)
            ->getQuery()->execute();
    }
}
