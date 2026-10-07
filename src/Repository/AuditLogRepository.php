<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AuditLog;
use App\Enum\AuditAction;
use App\Pagination\Paginator;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AuditLog>
 */
class AuditLogRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AuditLog::class);
    }

    /** @return Paginator<AuditLog> */
    public function searchPaginated(?string $actor, ?AuditAction $action, ?string $entityType, ?string $entityId, int $page, int $perPage = 50): Paginator
    {
        $qb = $this->createQueryBuilder('l')
            ->orderBy('l.createdAt', 'DESC')
            ->addOrderBy('l.id', 'DESC');
        if (null !== $actor && '' !== trim($actor)) {
            $qb->andWhere('l.actorIdentifier LIKE :actor')->setParameter('actor', '%'.addcslashes(trim($actor), '%_').'%');
        }
        if (null !== $action) {
            $qb->andWhere('l.action = :action')->setParameter('action', $action);
        }
        if (null !== $entityType && '' !== $entityType) {
            $qb->andWhere('l.entityType = :type')->setParameter('type', $entityType);
        }
        if (null !== $entityId && '' !== $entityId) {
            $qb->andWhere('l.entityId = :eid')->setParameter('eid', $entityId);
        }

        return Paginator::fromQuery($qb, $page, $perPage);
    }

    /** @return list<AuditLog> */
    public function findForEntity(string $entityType, string $entityId, int $limit = 20): array
    {
        return $this->createQueryBuilder('l')
            ->andWhere('l.entityType = :type')
            ->andWhere('l.entityId = :id')
            ->setParameter('type', $entityType)
            ->setParameter('id', $entityId)
            ->orderBy('l.createdAt', 'DESC')
            ->addOrderBy('l.id', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()->getResult();
    }

    /** @return list<string> */
    public function findEntityTypes(): array
    {
        return array_column($this->createQueryBuilder('l')
            ->select('DISTINCT l.entityType AS type')
            ->andWhere('l.entityType IS NOT NULL')
            ->orderBy('type')
            ->getQuery()->getScalarResult(), 'type');
    }
}
