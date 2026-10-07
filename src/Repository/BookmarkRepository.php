<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Bookmark;
use App\Entity\Story;
use App\Entity\User;
use App\Enum\StoryStatus;
use App\Pagination\Paginator;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Bookmark>
 */
class BookmarkRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Bookmark::class);
    }

    public function findOneByUserAndStory(User $user, Story $story): ?Bookmark
    {
        return $this->findOneBy(['user' => $user, 'story' => $story]);
    }

    /**
     * Which of the given stories are bookmarked by the user (one query for a whole page of cards).
     *
     * @param list<int> $storyIds
     *
     * @return array<int, true>
     */
    public function findBookmarkedStoryIds(User $user, array $storyIds): array
    {
        if ([] === $storyIds) {
            return [];
        }
        $rows = $this->createQueryBuilder('b')
            ->select('IDENTITY(b.story) AS storyId')
            ->andWhere('b.user = :user')
            ->andWhere('b.story IN (:ids)')
            ->setParameter('user', $user)
            ->setParameter('ids', $storyIds)
            ->getQuery()->getScalarResult();

        return array_fill_keys(array_map('intval', array_column($rows, 'storyId')), true);
    }

    /** @return Paginator<Bookmark> */
    public function paginateForUser(User $user, int $page, int $perPage = 18): Paginator
    {
        $qb = $this->createQueryBuilder('b')
            ->join('b.story', 's')->addSelect('s')
            ->leftJoin('s.coverImage', 'cover')->addSelect('cover')
            ->leftJoin('s.currentAudio', 'audio')->addSelect('audio')
            ->leftJoin('s.scripture', 'scripture')->addSelect('scripture')
            ->andWhere('b.user = :user')
            ->andWhere('s.status = :published')
            ->setParameter('user', $user)
            ->setParameter('published', StoryStatus::Published)
            ->orderBy('b.createdAt', 'DESC');

        return Paginator::fromQuery($qb, $page, $perPage);
    }

    public function countForUser(User $user): int
    {
        return $this->count(['user' => $user]);
    }

    public function countAll(): int
    {
        return $this->count([]);
    }

    /** @return array<int, int> storyId => bookmark count (for the aggregation job) */
    public function countPerStory(): array
    {
        $rows = $this->createQueryBuilder('b')
            ->select('IDENTITY(b.story) AS storyId, COUNT(b.id) AS total')
            ->groupBy('b.story')
            ->getQuery()->getScalarResult();
        $result = [];
        foreach ($rows as $row) {
            $result[(int) $row['storyId']] = (int) $row['total'];
        }

        return $result;
    }
}
