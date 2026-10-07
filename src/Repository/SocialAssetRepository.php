<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\SocialAsset;
use App\Entity\Story;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SocialAsset>
 */
class SocialAssetRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SocialAsset::class);
    }

    /** @return list<SocialAsset> */
    public function findForStory(Story $story, int $limit = 10): array
    {
        return $this->createQueryBuilder('a')
            ->andWhere('a.story = :story')
            ->setParameter('story', $story)
            ->orderBy('a.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()->getResult();
    }

    /** @return list<string> */
    public function findAllStorageKeys(): array
    {
        return array_column($this->createQueryBuilder('a')
            ->select('a.storageKey')
            ->andWhere('a.storageKey IS NOT NULL')
            ->getQuery()->getScalarResult(), 'storageKey');
    }
}
