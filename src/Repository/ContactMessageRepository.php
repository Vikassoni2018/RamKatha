<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ContactMessage;
use App\Pagination\Paginator;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ContactMessage>
 */
class ContactMessageRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ContactMessage::class);
    }

    /** @return Paginator<ContactMessage> */
    public function paginate(bool $archived, int $page, int $perPage = 25): Paginator
    {
        $qb = $this->createQueryBuilder('m')
            ->andWhere('m.archived = :archived')
            ->setParameter('archived', $archived)
            ->orderBy('m.createdAt', 'DESC');

        return Paginator::fromQuery($qb, $page, $perPage);
    }

    public function countUnread(): int
    {
        return (int) $this->createQueryBuilder('m')
            ->select('COUNT(m.id)')
            ->andWhere('m.readAt IS NULL')
            ->andWhere('m.archived = false')
            ->getQuery()->getSingleScalarResult();
    }
}
