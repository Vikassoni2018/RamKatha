<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Story;
use App\Entity\StoryProgress;
use App\Entity\User;
use App\Enum\StoryStatus;
use App\Pagination\Paginator;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<StoryProgress>
 */
class StoryProgressRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, StoryProgress::class);
    }

    public function findOneByUserAndStory(User $user, Story $story): ?StoryProgress
    {
        return $this->findOneBy(['user' => $user, 'story' => $story]);
    }

    /** @return list<StoryProgress> unfinished listening, most recent first */
    public function findContinueListening(User $user, int $limit = 6): array
    {
        return $this->createQueryBuilder('p')
            ->join('p.story', 's')->addSelect('s')
            ->leftJoin('s.coverImage', 'cover')->addSelect('cover')
            ->leftJoin('s.currentAudio', 'audio')->addSelect('audio')
            ->andWhere('p.user = :user')
            ->andWhere('p.completed = false')
            ->andWhere('p.audioPositionSeconds >= 5')
            ->andWhere('p.lastPlayedAt IS NOT NULL')
            ->andWhere('s.status = :published')
            ->setParameter('user', $user)
            ->setParameter('published', StoryStatus::Published)
            ->orderBy('p.lastPlayedAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()->getResult();
    }

    /** @return list<StoryProgress> started but unfinished reading, most recent first */
    public function findContinueReading(User $user, int $limit = 6): array
    {
        return $this->createQueryBuilder('p')
            ->join('p.story', 's')->addSelect('s')
            ->leftJoin('s.coverImage', 'cover')->addSelect('cover')
            ->andWhere('p.user = :user')
            ->andWhere('p.readingProgressPercent BETWEEN 5 AND 90')
            ->andWhere('p.lastReadAt IS NOT NULL')
            ->andWhere('s.status = :published')
            ->setParameter('user', $user)
            ->setParameter('published', StoryStatus::Published)
            ->orderBy('p.lastReadAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()->getResult();
    }

    /** @return Paginator<StoryProgress> listening/reading history */
    public function paginateHistory(User $user, int $page, int $perPage = 20): Paginator
    {
        $qb = $this->createQueryBuilder('p')
            ->join('p.story', 's')->addSelect('s')
            ->leftJoin('s.coverImage', 'cover')->addSelect('cover')
            ->andWhere('p.user = :user')
            ->andWhere('s.status = :published')
            ->setParameter('user', $user)
            ->setParameter('published', StoryStatus::Published)
            ->orderBy('p.updatedAt', 'DESC');

        return Paginator::fromQuery($qb, $page, $perPage);
    }

    public function countCompleted(): int
    {
        return $this->count(['completed' => true]);
    }
}
