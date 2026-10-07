<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\SocialPost;
use App\Entity\Story;
use App\Enum\SocialPostStatus;
use App\Pagination\Paginator;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SocialPost>
 */
class SocialPostRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SocialPost::class);
    }

    /** @return Paginator<SocialPost> */
    public function searchPaginated(?SocialPostStatus $status, int $page, int $perPage = 25): Paginator
    {
        $qb = $this->createQueryBuilder('p')
            ->join('p.story', 's')->addSelect('s')
            ->join('p.account', 'a')->addSelect('a')
            ->leftJoin('p.createdBy', 'u')->addSelect('u')
            ->orderBy('p.createdAt', 'DESC');
        if (null !== $status) {
            $qb->andWhere('p.status = :status')->setParameter('status', $status);
        }

        return Paginator::fromQuery($qb, $page, $perPage);
    }

    /** @return list<int> queued posts whose time has come (or with no schedule) */
    public function findDueIds(\DateTimeImmutable $now, int $limit = 20): array
    {
        return array_map('intval', array_column($this->createQueryBuilder('p')
            ->select('p.id')
            ->andWhere('p.status = :queued')
            ->andWhere('p.scheduledAt IS NULL OR p.scheduledAt <= :now')
            ->setParameter('queued', SocialPostStatus::Queued)
            ->setParameter('now', $now)
            ->orderBy('p.scheduledAt', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()->getScalarResult(), 'id'));
    }

    /** @return list<int> failed posts eligible for an automatic retry */
    public function findAutoRetryIds(\DateTimeImmutable $lastAttemptBefore, int $limit = 10): array
    {
        return array_map('intval', array_column($this->createQueryBuilder('p')
            ->select('p.id')
            ->andWhere('p.status = :failed')
            ->andWhere('p.lastErrorRetryable = true')
            ->andWhere('p.attemptCount < :max')
            ->andWhere('p.lastAttemptAt <= :before')
            ->setParameter('failed', SocialPostStatus::Failed)
            ->setParameter('max', SocialPost::MAX_AUTO_ATTEMPTS)
            ->setParameter('before', $lastAttemptBefore)
            ->setMaxResults($limit)
            ->getQuery()->getScalarResult(), 'id'));
    }

    /** Atomically moves a post to "publishing". Returns false when another process owns it. */
    public function claimForPublishing(SocialPost $post, \DateTimeImmutable $staleBefore): bool
    {
        $affected = $this->getEntityManager()->getConnection()->executeStatement(
            'UPDATE social_post SET status = :publishing, updated_at = :now
             WHERE id = :id AND (status IN (:claimable) OR (status = :publishing AND updated_at < :stale))',
            [
                'publishing' => SocialPostStatus::Publishing->value,
                'now' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
                'id' => $post->getId(),
                'claimable' => [SocialPostStatus::Draft->value, SocialPostStatus::Queued->value, SocialPostStatus::Failed->value],
                'stale' => $staleBefore->format('Y-m-d H:i:s'),
            ],
            ['claimable' => \Doctrine\DBAL\ArrayParameterType::STRING],
        );

        return 1 === $affected;
    }

    /** @return list<SocialPost> */
    public function findForStory(Story $story): array
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.story = :story')
            ->setParameter('story', $story)
            ->orderBy('p.createdAt', 'DESC')
            ->getQuery()->getResult();
    }

    public function hasActiveOrPublishedForStory(Story $story): bool
    {
        return (int) $this->createQueryBuilder('p')
            ->select('COUNT(p.id)')
            ->andWhere('p.story = :story')
            ->andWhere('p.status IN (:statuses)')
            ->setParameter('story', $story)
            ->setParameter('statuses', [SocialPostStatus::Queued, SocialPostStatus::Publishing, SocialPostStatus::Published])
            ->getQuery()->getSingleScalarResult() > 0;
    }

    /** @return array<string, int> */
    public function countByStatus(): array
    {
        $rows = $this->createQueryBuilder('p')
            ->select('p.status AS status, COUNT(p.id) AS total')
            ->groupBy('p.status')
            ->getQuery()->getArrayResult();
        $counts = [];
        foreach ($rows as $row) {
            $status = $row['status'] instanceof SocialPostStatus ? $row['status']->value : (string) $row['status'];
            $counts[$status] = (int) $row['total'];
        }

        return $counts;
    }
}
