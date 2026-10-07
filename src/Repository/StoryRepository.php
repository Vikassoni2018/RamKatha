<?php

declare(strict_types=1);

namespace App\Repository;

use App\DTO\StoryListFilter;
use App\Entity\Story;
use App\Entity\Taxonomy\Festival;
use App\Entity\Taxonomy\Series;
use App\Entity\User;
use App\Enum\AudioStatus;
use App\Enum\StoryStatus;
use App\Enum\VerificationStatus;
use App\Pagination\Paginator;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * Query patterns are designed around the composite indexes declared on Story:
 *  (status, published_at), (featured, status, published_at), (status, popularity_score),
 *  (status, audio_duration_seconds), (status, scheduled_at), (series_id, series_position).
 *
 * Block queries return IDs (cacheable) and cards are hydrated in a single query with
 * findCardsByIds(), avoiding N+1 queries on the homepage and listings.
 *
 * @extends ServiceEntityRepository<Story>
 */
class StoryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Story::class);
    }

    private function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable();
    }

    /** Published, visible stories. Joins only to-one relations needed for cards. */
    public function createPublishedQueryBuilder(string $alias = 's'): QueryBuilder
    {
        return $this->createQueryBuilder($alias)
            ->andWhere($alias.'.status = :published')
            ->andWhere($alias.'.publishedAt <= :now')
            ->setParameter('published', StoryStatus::Published)
            ->setParameter('now', $this->now());
    }

    private function idsFrom(QueryBuilder $qb): array
    {
        return array_map('intval', array_column($qb->select('s.id')->getQuery()->getScalarResult(), 'id'));
    }

    /** Full story for the public detail page (single query for all to-one relations). */
    public function findPublishedBySlug(string $slug): ?Story
    {
        $story = $this->createPublishedQueryBuilder()
            ->andWhere('s.slug = :slug')
            ->setParameter('slug', $slug)
            ->leftJoin('s.coverImage', 'cover')->addSelect('cover')
            ->leftJoin('s.ogImage', 'og')->addSelect('og')
            ->leftJoin('s.currentAudio', 'audio')->addSelect('audio')
            ->leftJoin('s.scripture', 'scripture')->addSelect('scripture')
            ->leftJoin('s.series', 'series')->addSelect('series')
            ->leftJoin('s.sourceVerifiedBy', 'verifier')->addSelect('verifier')
            ->getQuery()->getOneOrNullResult();

        return $story;
    }

    /** Story with every relation needed by the admin editor / preview (any status). */
    public function findOneWithRelations(int $id): ?Story
    {
        $story = $this->createQueryBuilder('s')
            ->andWhere('s.id = :id')
            ->setParameter('id', $id)
            ->leftJoin('s.coverImage', 'cover')->addSelect('cover')
            ->leftJoin('s.ogImage', 'og')->addSelect('og')
            ->leftJoin('s.currentAudio', 'audio')->addSelect('audio')
            ->leftJoin('s.scripture', 'scripture')->addSelect('scripture')
            ->leftJoin('s.series', 'series')->addSelect('series')
            ->leftJoin('s.createdBy', 'author')->addSelect('author')
            ->getQuery()->getOneOrNullResult();

        return $story;
    }

    public function findOneByUuid(Uuid $uuid): ?Story
    {
        return $this->findOneBy(['uuid' => $uuid]);
    }

    public function findPublishedByUuid(Uuid $uuid): ?Story
    {
        return $this->createPublishedQueryBuilder()
            ->andWhere('s.uuid = :uuid')
            ->setParameter('uuid', $uuid, 'uuid')
            ->getQuery()->getOneOrNullResult();
    }

    /**
     * Hydrates story cards for the given ids, preserving order (2 queries total).
     *
     * @param list<int> $ids
     *
     * @return list<Story>
     */
    public function findCardsByIds(array $ids): array
    {
        $ids = array_values(array_unique(array_filter($ids)));
        if ([] === $ids) {
            return [];
        }

        $stories = $this->createQueryBuilder('s')
            ->leftJoin('s.coverImage', 'cover')->addSelect('cover')
            ->leftJoin('s.currentAudio', 'audio')->addSelect('audio')
            ->leftJoin('s.scripture', 'scripture')->addSelect('scripture')
            ->leftJoin('s.series', 'series')->addSelect('series')
            ->leftJoin('s.categories', 'cat')->addSelect('cat')
            ->andWhere('s.id IN (:ids)')
            ->setParameter('ids', $ids)
            ->getQuery()->getResult();

        $byId = [];
        foreach ($stories as $story) {
            $byId[$story->getId()] = $story;
        }

        return array_values(array_filter(array_map(static fn (int $id) => $byId[$id] ?? null, $ids)));
    }

    /** @return list<int> */
    public function findFeaturedIds(int $limit): array
    {
        return $this->idsFrom($this->createPublishedQueryBuilder()
            ->andWhere('s.featured = true')
            ->orderBy('s.publishedAt', 'DESC')
            ->setMaxResults($limit));
    }

    /** @return list<int> */
    public function findLatestIds(int $limit): array
    {
        return $this->idsFrom($this->createPublishedQueryBuilder()
            ->orderBy('s.publishedAt', 'DESC')
            ->setMaxResults($limit));
    }

    /** @return list<int> */
    public function findPopularIds(int $limit): array
    {
        return $this->idsFrom($this->createPublishedQueryBuilder()
            ->andWhere('s.popularityScore > 0')
            ->orderBy('s.popularityScore', 'DESC')
            ->addOrderBy('s.publishedAt', 'DESC')
            ->setMaxResults($limit));
    }

    /** Stories with ready audio whose duration falls in the given range. @return list<int> */
    public function findAudioIdsByDuration(?int $minSeconds, ?int $maxSeconds, int $limit): array
    {
        $qb = $this->createPublishedQueryBuilder()
            ->andWhere('s.audioStatus = :ready')
            ->andWhere('s.audioEnabled = true')
            ->setParameter('ready', AudioStatus::Ready)
            ->orderBy('s.popularityScore', 'DESC')
            ->addOrderBy('s.publishedAt', 'DESC')
            ->setMaxResults($limit);
        if (null !== $minSeconds) {
            $qb->andWhere('s.audioDurationSeconds >= :min')->setParameter('min', $minSeconds);
        }
        if (null !== $maxSeconds) {
            $qb->andWhere('s.audioDurationSeconds <= :max')->setParameter('max', $maxSeconds);
        }

        return $this->idsFrom($qb);
    }

    /** @return list<int> */
    public function findLatestAudioIds(int $limit): array
    {
        return $this->idsFrom($this->createPublishedQueryBuilder()
            ->andWhere('s.audioStatus = :ready')
            ->andWhere('s.audioEnabled = true')
            ->setParameter('ready', AudioStatus::Ready)
            ->orderBy('s.publishedAt', 'DESC')
            ->setMaxResults($limit));
    }

    /** @return list<int> */
    public function findIdsByFestival(Festival $festival, int $limit): array
    {
        return $this->idsFrom($this->createPublishedQueryBuilder()
            ->join('s.festivals', 'f')
            ->andWhere('f = :festival')
            ->setParameter('festival', $festival)
            ->orderBy('s.popularityScore', 'DESC')
            ->addOrderBy('s.publishedAt', 'DESC')
            ->setMaxResults($limit));
    }

    /** @return Paginator<Story> */
    public function paginatePublished(StoryListFilter $filter, int $page, int $perPage = 18): Paginator
    {
        $qb = $this->createPublishedQueryBuilder()
            ->leftJoin('s.coverImage', 'cover')->addSelect('cover')
            ->leftJoin('s.currentAudio', 'audio')->addSelect('audio')
            ->leftJoin('s.scripture', 'scripture')->addSelect('scripture')
            ->leftJoin('s.series', 'series')->addSelect('series')
            ->leftJoin('s.categories', 'cat')->addSelect('cat');

        $this->applyFilter($qb, $filter);

        match ($filter->sort) {
            StoryListFilter::SORT_POPULAR => $qb->orderBy('s.popularityScore', 'DESC')->addOrderBy('s.publishedAt', 'DESC'),
            StoryListFilter::SORT_OLDEST => $qb->orderBy('s.publishedAt', 'ASC'),
            StoryListFilter::SORT_SHORTEST => $qb->orderBy('s.readingTimeMinutes', 'ASC')->addOrderBy('s.publishedAt', 'DESC'),
            StoryListFilter::SORT_SERIES => $qb->orderBy('s.seriesPosition', 'ASC')->addOrderBy('s.publishedAt', 'ASC'),
            default => $qb->orderBy('s.publishedAt', 'DESC'),
        };
        $qb->addOrderBy('s.id', 'DESC');

        return Paginator::fromQuery($qb, $page, $perPage, fetchJoinCollection: true);
    }

    private function applyFilter(QueryBuilder $qb, StoryListFilter $filter): void
    {
        $memberships = [
            'categories' => $filter->category,
            'deities' => $filter->deity,
            'tags' => $filter->tag,
            'festivals' => $filter->festival,
        ];
        foreach ($memberships as $association => $term) {
            if (null !== $term) {
                $param = 'term_'.$association;
                $qb->andWhere(':'.$param.' MEMBER OF s.'.$association)->setParameter($param, $term);
            }
        }
        if (null !== $filter->scripture) {
            $qb->andWhere('s.scripture = :scripture')->setParameter('scripture', $filter->scripture);
        }
        if (null !== $filter->series) {
            $qb->andWhere('s.series = :seriesFilter')->setParameter('seriesFilter', $filter->series);
        }
        if (null !== $filter->sourceType) {
            $qb->andWhere('s.sourceType = :sourceType')->setParameter('sourceType', $filter->sourceType);
        }
        if (true === $filter->hasAudio) {
            $qb->andWhere('s.audioStatus = :audioReady AND s.audioEnabled = true')->setParameter('audioReady', AudioStatus::Ready);
        } elseif (false === $filter->hasAudio) {
            $qb->andWhere('(s.audioStatus IS NULL OR s.audioStatus != :audioReady OR s.audioEnabled = false)')->setParameter('audioReady', AudioStatus::Ready);
        }
        [$min, $max] = $filter->durationRange();
        // Listening time if audio exists, otherwise estimated reading time.
        $durationExpr = 'COALESCE(s.audioDurationSeconds, s.readingTimeMinutes * 60)';
        if (null !== $min) {
            $qb->andWhere($durationExpr.' >= :minDuration')->setParameter('minDuration', $min);
        }
        if (null !== $max) {
            $qb->andWhere($durationExpr.' <= :maxDuration')->setParameter('maxDuration', $max);
        }
    }

    /**
     * Related stories: same series, deities or categories; ordered by popularity.
     *
     * @return list<Story>
     */
    public function findRelated(Story $story, int $limit = 6): array
    {
        $categoryIds = $story->getCategories()->map(static fn ($c) => $c->getId())->getValues();
        $deityIds = $story->getDeities()->map(static fn ($d) => $d->getId())->getValues();

        $qb = $this->createPublishedQueryBuilder()
            ->andWhere('s.id != :self')
            ->setParameter('self', $story->getId());

        $or = [];
        if (null !== $story->getSeries()) {
            $or[] = 's.series = :series';
            $qb->setParameter('series', $story->getSeries());
        }
        if (null !== $story->getScripture()) {
            $or[] = 's.scripture = :scripture';
            $qb->setParameter('scripture', $story->getScripture());
        }
        if ([] !== $categoryIds) {
            $or[] = 'EXISTS (SELECT 1 FROM '.Story::class.' s2 JOIN s2.categories c2 WHERE s2.id = s.id AND c2.id IN (:categoryIds))';
            $qb->setParameter('categoryIds', $categoryIds);
        }
        if ([] !== $deityIds) {
            $or[] = 'EXISTS (SELECT 1 FROM '.Story::class.' s3 JOIN s3.deities d3 WHERE s3.id = s.id AND d3.id IN (:deityIds))';
            $qb->setParameter('deityIds', $deityIds);
        }
        if ([] === $or) {
            $latest = array_values(array_diff($this->findLatestIds($limit + 1), [$story->getId()]));

            return $this->findCardsByIds(\array_slice($latest, 0, $limit));
        }
        $qb->andWhere(implode(' OR ', $or))
            ->orderBy('s.popularityScore', 'DESC')
            ->addOrderBy('s.publishedAt', 'DESC')
            ->setMaxResults($limit);

        return $this->findCardsByIds($this->idsFrom($qb));
    }

    /** @return array{previous: Story|null, next: Story|null} */
    public function findSeriesNeighbours(Story $story): array
    {
        $series = $story->getSeries();
        $position = $story->getSeriesPosition();
        if (null === $series || null === $position) {
            return ['previous' => null, 'next' => null];
        }

        $previous = $this->createPublishedQueryBuilder()
            ->andWhere('s.series = :series')
            ->andWhere('s.seriesPosition < :position')
            ->setParameter('series', $series)
            ->setParameter('position', $position)
            ->orderBy('s.seriesPosition', 'DESC')
            ->setMaxResults(1)
            ->getQuery()->getOneOrNullResult();

        $next = $this->createPublishedQueryBuilder()
            ->andWhere('s.series = :series')
            ->andWhere('s.seriesPosition > :position')
            ->setParameter('series', $series)
            ->setParameter('position', $position)
            ->orderBy('s.seriesPosition', 'ASC')
            ->setMaxResults(1)
            ->getQuery()->getOneOrNullResult();

        return ['previous' => $previous, 'next' => $next];
    }

    /** @return list<array{title: string, slug: string, position: int|null}> lightweight series outline */
    public function findSeriesOutline(Series $series): array
    {
        return $this->createPublishedQueryBuilder()
            ->select('s.title AS title, s.slug AS slug, s.seriesPosition AS position')
            ->andWhere('s.series = :series')
            ->setParameter('series', $series)
            ->orderBy('s.seriesPosition', 'ASC')
            ->addOrderBy('s.publishedAt', 'ASC')
            ->getQuery()->getArrayResult();
    }

    /** @return list<int> stories whose schedule time has passed */
    public function findDueScheduledIds(\DateTimeImmutable $now, int $limit = 50): array
    {
        return array_map('intval', array_column($this->createQueryBuilder('s')
            ->select('s.id')
            ->andWhere('s.status = :scheduled')
            ->andWhere('s.scheduledAt <= :now')
            ->setParameter('scheduled', StoryStatus::Scheduled)
            ->setParameter('now', $now)
            ->orderBy('s.scheduledAt', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()->getScalarResult(), 'id'));
    }

    /** @return list<Story> */
    public function findUpcomingScheduled(int $limit = 10): array
    {
        return $this->createQueryBuilder('s')
            ->andWhere('s.status = :scheduled')
            ->setParameter('scheduled', StoryStatus::Scheduled)
            ->orderBy('s.scheduledAt', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()->getResult();
    }

    /** @return array<string, int> status => count */
    public function countByStatus(): array
    {
        $rows = $this->createQueryBuilder('s')
            ->select('s.status AS status, COUNT(s.id) AS total')
            ->groupBy('s.status')
            ->getQuery()->getArrayResult();

        $counts = array_fill_keys(array_map(static fn (StoryStatus $s) => $s->value, StoryStatus::cases()), 0);
        foreach ($rows as $row) {
            $status = $row['status'] instanceof StoryStatus ? $row['status']->value : (string) $row['status'];
            $counts[$status] = (int) $row['total'];
        }

        return $counts;
    }

    /** @return array<string, int> audio status => count (stories with audio enabled) */
    public function countByAudioStatus(): array
    {
        $rows = $this->createQueryBuilder('s')
            ->select('s.audioStatus AS status, COUNT(s.id) AS total')
            ->andWhere('s.audioStatus IS NOT NULL')
            ->groupBy('s.audioStatus')
            ->getQuery()->getArrayResult();

        $counts = [];
        foreach ($rows as $row) {
            $status = $row['status'] instanceof AudioStatus ? $row['status']->value : (string) $row['status'];
            $counts[$status] = (int) $row['total'];
        }

        return $counts;
    }

    /**
     * Admin listing with filters.
     *
     * @param array{q?: ?string, status?: ?StoryStatus, audio?: ?AudioStatus, verification?: ?VerificationStatus, author?: ?User, featured?: ?bool, category?: mixed} $filters
     *
     * @return Paginator<Story>
     */
    public function adminSearch(array $filters, int $page, int $perPage = 25): Paginator
    {
        $qb = $this->createQueryBuilder('s')
            ->leftJoin('s.createdBy', 'author')->addSelect('author')
            ->leftJoin('s.coverImage', 'cover')->addSelect('cover')
            ->leftJoin('s.scripture', 'scripture')->addSelect('scripture')
            ->orderBy('s.updatedAt', 'DESC');

        if (!empty($filters['q'])) {
            $qb->andWhere('s.title LIKE :q OR s.slug LIKE :q OR s.originalTitle LIKE :q')
                ->setParameter('q', '%'.addcslashes(trim($filters['q']), '%_').'%');
        }
        if (!empty($filters['status'])) {
            $qb->andWhere('s.status = :status')->setParameter('status', $filters['status']);
        } else {
            $qb->andWhere('s.status != :archived')->setParameter('archived', StoryStatus::Archived);
        }
        if (!empty($filters['audio'])) {
            $qb->andWhere('s.audioStatus = :audio')->setParameter('audio', $filters['audio']);
        }
        if (!empty($filters['verification'])) {
            $qb->andWhere('s.verificationStatus = :verification')->setParameter('verification', $filters['verification']);
        }
        if (!empty($filters['author'])) {
            $qb->andWhere('s.createdBy = :author')->setParameter('author', $filters['author']);
        }
        if (isset($filters['featured']) && null !== $filters['featured']) {
            $qb->andWhere('s.featured = :featured')->setParameter('featured', $filters['featured']);
        }
        if (!empty($filters['category'])) {
            $qb->andWhere(':category MEMBER OF s.categories')->setParameter('category', $filters['category']);
        }

        return Paginator::fromQuery($qb, $page, $perPage);
    }

    /** @return iterable<array{slug: string, updatedAt: \DateTimeImmutable, publishedAt: \DateTimeImmutable}> */
    public function iterateForSitemap(): iterable
    {
        return $this->createPublishedQueryBuilder()
            ->select('s.slug AS slug, s.updatedAt AS updatedAt, s.publishedAt AS publishedAt')
            // Same rule as Story::hasEnglishVersion() (the setters store blank English text as NULL).
            ->addSelect('(CASE WHEN s.titleEn IS NOT NULL AND s.excerptEn IS NOT NULL AND s.bodyEn IS NOT NULL THEN 1 ELSE 0 END) AS hasEnglish')
            ->andWhere('s.robotsIndex = true')
            ->andWhere('s.canonicalUrl IS NULL')
            ->orderBy('s.publishedAt', 'DESC')
            ->getQuery()->toIterable([], \Doctrine\ORM\AbstractQuery::HYDRATE_ARRAY);
    }

    public function countPublished(): int
    {
        return (int) $this->createPublishedQueryBuilder()->select('COUNT(s.id)')->getQuery()->getSingleScalarResult();
    }

    public function slugExists(string $slug, ?int $excludeId = null): bool
    {
        $qb = $this->createQueryBuilder('s')
            ->select('COUNT(s.id)')
            ->andWhere('s.slug = :slug')
            ->setParameter('slug', $slug);
        if (null !== $excludeId) {
            $qb->andWhere('s.id != :id')->setParameter('id', $excludeId);
        }

        return (int) $qb->getQuery()->getSingleScalarResult() > 0;
    }

    /** Next free position at the end of a series. */
    public function nextSeriesPosition(Series $series): int
    {
        $max = $this->createQueryBuilder('s')
            ->select('MAX(s.seriesPosition)')
            ->andWhere('s.series = :series')
            ->setParameter('series', $series)
            ->getQuery()->getSingleScalarResult();

        return null === $max ? 1 : ((int) $max) + 1;
    }

    /** @return list<Story> stories that use a media file as cover or OG image */
    public function findUsingMedia(int $mediaId, int $limit = 20): array
    {
        return $this->createQueryBuilder('s')
            ->andWhere('IDENTITY(s.coverImage) = :media OR IDENTITY(s.ogImage) = :media')
            ->setParameter('media', $mediaId)
            ->setMaxResults($limit)
            ->getQuery()->getResult();
    }
}
