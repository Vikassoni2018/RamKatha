<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\CollectionItem;
use App\Entity\Story;
use App\Entity\User;
use App\Entity\UserCollection;
use App\Enum\CollectionVisibility;
use App\Pagination\Paginator;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<UserCollection>
 */
class UserCollectionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, UserCollection::class);
    }

    public function findOneByUuid(Uuid $uuid): ?UserCollection
    {
        return $this->createQueryBuilder('c')
            ->join('c.user', 'u')->addSelect('u')
            ->andWhere('c.uuid = :uuid')
            ->setParameter('uuid', $uuid, 'uuid')
            ->getQuery()->getOneOrNullResult();
    }

    /**
     * The user's collections with item counts (one query).
     *
     * @return list<array{collection: UserCollection, itemCount: int}>
     */
    public function findForUserWithCounts(User $user): array
    {
        $rows = $this->createQueryBuilder('c')
            ->select('c AS collection, COUNT(i.id) AS itemCount')
            ->leftJoin('c.items', 'i')
            ->andWhere('c.user = :user')
            ->setParameter('user', $user)
            ->groupBy('c.id')
            ->orderBy('c.updatedAt', 'DESC')
            ->getQuery()->getResult();

        return array_map(static fn (array $row) => ['collection' => $row['collection'], 'itemCount' => (int) $row['itemCount']], $rows);
    }

    /**
     * For the "add to collection" picker: user collections and whether the story is in each.
     *
     * @return list<array{uuid: string, name: string, contains: bool, count: int}>
     */
    public function findPickerData(User $user, Story $story): array
    {
        $rows = $this->getEntityManager()->createQueryBuilder()
            ->select('c.uuid AS uuid, c.name AS name, COUNT(i.id) AS total, SUM(CASE WHEN IDENTITY(i.story) = :storyId THEN 1 ELSE 0 END) AS hasStory')
            ->from(UserCollection::class, 'c')
            ->leftJoin('c.items', 'i')
            ->andWhere('c.user = :user')
            ->setParameter('user', $user)
            ->setParameter('storyId', $story->getId())
            ->groupBy('c.id')
            ->orderBy('c.updatedAt', 'DESC')
            ->getQuery()->getArrayResult();

        return array_map(static fn (array $r) => [
            'uuid' => $r['uuid'] instanceof Uuid ? $r['uuid']->toRfc4122() : (string) $r['uuid'],
            'name' => $r['name'],
            'contains' => (int) $r['hasStory'] > 0,
            'count' => (int) $r['total'],
        ], $rows);
    }

    public function countForUser(User $user): int
    {
        return $this->count(['user' => $user]);
    }

    public function slugExistsForUser(User $user, string $slug, ?int $excludeId = null): bool
    {
        $qb = $this->createQueryBuilder('c')
            ->select('COUNT(c.id)')
            ->andWhere('c.user = :user')
            ->andWhere('c.slug = :slug')
            ->setParameter('user', $user)
            ->setParameter('slug', $slug);
        if (null !== $excludeId) {
            $qb->andWhere('c.id != :id')->setParameter('id', $excludeId);
        }

        return (int) $qb->getQuery()->getSingleScalarResult() > 0;
    }

    /** @return Paginator<UserCollection> public collections (for discovery) */
    public function findPublicPaginated(int $page, int $perPage = 24): Paginator
    {
        $qb = $this->createQueryBuilder('c')
            ->join('c.user', 'u')->addSelect('u')
            ->andWhere('c.visibility = :public')
            ->andWhere('u.isActive = true')
            ->andWhere('SIZE(c.items) > 0')
            ->setParameter('public', CollectionVisibility::Public)
            ->orderBy('c.updatedAt', 'DESC');

        return Paginator::fromQuery($qb, $page, $perPage);
    }

    /** @return Paginator<UserCollection> admin moderation list */
    public function adminSearch(?string $query, ?CollectionVisibility $visibility, int $page, int $perPage = 25): Paginator
    {
        $qb = $this->createQueryBuilder('c')
            ->join('c.user', 'u')->addSelect('u')
            ->orderBy('c.updatedAt', 'DESC');
        if (null !== $query && '' !== trim($query)) {
            $qb->andWhere('c.name LIKE :q OR u.email LIKE :q')->setParameter('q', '%'.addcslashes(trim($query), '%_').'%');
        }
        if (null !== $visibility) {
            $qb->andWhere('c.visibility = :visibility')->setParameter('visibility', $visibility);
        }

        return Paginator::fromQuery($qb, $page, $perPage);
    }

    /**
     * Items of a collection with published stories only (cards hydrated in one query).
     *
     * @return list<CollectionItem>
     */
    public function findVisibleItems(UserCollection $collection): array
    {
        return $this->getEntityManager()->createQueryBuilder()
            ->select('i', 's', 'cover', 'audio', 'scripture')
            ->from(CollectionItem::class, 'i')
            ->join('i.story', 's')
            ->leftJoin('s.coverImage', 'cover')
            ->leftJoin('s.currentAudio', 'audio')
            ->leftJoin('s.scripture', 'scripture')
            ->andWhere('i.collection = :collection')
            ->andWhere('s.status = :published')
            ->setParameter('collection', $collection)
            ->setParameter('published', \App\Enum\StoryStatus::Published)
            ->orderBy('i.position', 'ASC')
            ->addOrderBy('i.id', 'ASC')
            ->getQuery()->getResult();
    }

    public function maxPosition(UserCollection $collection): int
    {
        return (int) $this->getEntityManager()->createQueryBuilder()
            ->select('MAX(i.position)')
            ->from(CollectionItem::class, 'i')
            ->andWhere('i.collection = :collection')
            ->setParameter('collection', $collection)
            ->getQuery()->getSingleScalarResult();
    }

    public function findItem(UserCollection $collection, Story $story): ?CollectionItem
    {
        return $this->getEntityManager()->getRepository(CollectionItem::class)->findOneBy(['collection' => $collection, 'story' => $story]);
    }

    public function countAll(): int
    {
        return $this->count([]);
    }
}
