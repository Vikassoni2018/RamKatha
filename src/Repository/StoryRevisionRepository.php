<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Story;
use App\Entity\StoryRevision;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<StoryRevision>
 */
class StoryRevisionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, StoryRevision::class);
    }

    public function findLatestVersionNumber(Story $story): int
    {
        return (int) $this->createQueryBuilder('r')
            ->select('MAX(r.version)')
            ->andWhere('r.story = :story')
            ->setParameter('story', $story)
            ->getQuery()->getSingleScalarResult();
    }

    public function findLatest(Story $story): ?StoryRevision
    {
        return $this->createQueryBuilder('r')
            ->andWhere('r.story = :story')
            ->setParameter('story', $story)
            ->orderBy('r.version', 'DESC')
            ->setMaxResults(1)
            ->getQuery()->getOneOrNullResult();
    }

    /** @return list<array{id: int, version: int, title: string, changeSummary: ?string, createdAt: \DateTimeImmutable, changedBy: ?string}> */
    public function findSummariesForStory(Story $story): array
    {
        return $this->createQueryBuilder('r')
            ->select('r.id, r.version, r.title, r.changeSummary, r.createdAt, u.displayName AS changedBy')
            ->leftJoin('r.changedBy', 'u')
            ->andWhere('r.story = :story')
            ->setParameter('story', $story)
            ->orderBy('r.version', 'DESC')
            ->getQuery()->getArrayResult();
    }

    public function findOneForStory(Story $story, int $version): ?StoryRevision
    {
        return $this->findOneBy(['story' => $story, 'version' => $version]);
    }
}
