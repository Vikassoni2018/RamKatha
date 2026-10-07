<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AdminNotification;
use App\Enum\NotificationLevel;
use App\Pagination\Paginator;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AdminNotification>
 */
class AdminNotificationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AdminNotification::class);
    }

    public function countUnread(): int
    {
        return (int) $this->createQueryBuilder('n')
            ->select('COUNT(n.id)')
            ->andWhere('n.readAt IS NULL')
            ->getQuery()->getSingleScalarResult();
    }

    /** @return list<AdminNotification> */
    public function findRecent(int $limit = 10, bool $unreadOnly = false): array
    {
        $qb = $this->createQueryBuilder('n')
            ->orderBy('n.createdAt', 'DESC')
            ->setMaxResults($limit);
        if ($unreadOnly) {
            $qb->andWhere('n.readAt IS NULL');
        }

        return $qb->getQuery()->getResult();
    }

    /** @return list<AdminNotification> unread failures for the dashboard */
    public function findUnreadErrors(int $limit = 10): array
    {
        return $this->createQueryBuilder('n')
            ->andWhere('n.readAt IS NULL')
            ->andWhere('n.level = :error')
            ->setParameter('error', NotificationLevel::Error)
            ->orderBy('n.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()->getResult();
    }

    /** @return Paginator<AdminNotification> */
    public function paginate(int $page, int $perPage = 30): Paginator
    {
        return Paginator::fromQuery($this->createQueryBuilder('n')->orderBy('n.createdAt', 'DESC'), $page, $perPage);
    }

    public function markAllRead(\DateTimeImmutable $at): int
    {
        return $this->createQueryBuilder('n')
            ->update()
            ->set('n.readAt', ':at')
            ->andWhere('n.readAt IS NULL')
            ->setParameter('at', $at)
            ->getQuery()->execute();
    }

    public function deleteReadBefore(\DateTimeImmutable $before): int
    {
        return $this->createQueryBuilder('n')
            ->delete()
            ->andWhere('n.readAt IS NOT NULL')
            ->andWhere('n.createdAt < :before')
            ->setParameter('before', $before)
            ->getQuery()->execute();
    }
}
