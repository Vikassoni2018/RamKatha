<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\StorySlugHistory;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<StorySlugHistory>
 */
class StorySlugHistoryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, StorySlugHistory::class);
    }

    public function findOneBySlug(string $slug): ?StorySlugHistory
    {
        return $this->createQueryBuilder('h')
            ->join('h.story', 's')->addSelect('s')
            ->andWhere('h.slug = :slug')
            ->setParameter('slug', $slug)
            ->getQuery()->getOneOrNullResult();
    }
}
