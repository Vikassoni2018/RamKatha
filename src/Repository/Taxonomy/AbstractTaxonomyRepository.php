<?php

declare(strict_types=1);

namespace App\Repository\Taxonomy;

use App\Entity\Story;
use App\Entity\Taxonomy\AbstractTaxonomy;
use App\Enum\StoryStatus;
use App\Pagination\Paginator;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;

/**
 * Shared queries for all taxonomies.
 *
 * @template T of AbstractTaxonomy
 *
 * @extends ServiceEntityRepository<T>
 */
abstract class AbstractTaxonomyRepository extends ServiceEntityRepository
{
    /** Name of the Story association pointing at this taxonomy (e.g. "categories", "scripture"). */
    abstract protected function storyAssociation(): string;

    /** @return list<T> */
    public function findAllOrdered(): array
    {
        return $this->createQueryBuilder('t')
            ->orderBy('t.position', 'ASC')
            ->addOrderBy('t.name', 'ASC')
            ->getQuery()->getResult();
    }

    /** @return list<T> */
    public function findActiveOrdered(?int $limit = null): array
    {
        $qb = $this->createQueryBuilder('t')
            ->leftJoin('t.coverImage', 'c')->addSelect('c')
            ->andWhere('t.isActive = true')
            ->orderBy('t.position', 'ASC')
            ->addOrderBy('t.name', 'ASC');
        if (null !== $limit) {
            $qb->setMaxResults($limit);
        }

        return $qb->getQuery()->getResult();
    }

    /** @return T|null */
    public function findOneActiveBySlug(string $slug): ?AbstractTaxonomy
    {
        return $this->createQueryBuilder('t')
            ->leftJoin('t.coverImage', 'c')->addSelect('c')
            ->andWhere('t.slug = :slug')
            ->andWhere('t.isActive = true')
            ->setParameter('slug', $slug)
            ->getQuery()->getOneOrNullResult();
    }

    /** @return Paginator<T> */
    public function searchPaginated(?string $query, int $page, int $perPage = 30): Paginator
    {
        $qb = $this->createQueryBuilder('t')
            ->orderBy('t.position', 'ASC')
            ->addOrderBy('t.name', 'ASC');
        if (null !== $query && '' !== trim($query)) {
            $qb->andWhere('t.name LIKE :q OR t.nameEn LIKE :q OR t.slug LIKE :q')
                ->setParameter('q', '%'.addcslashes(trim($query), '%_').'%');
        }

        return Paginator::fromQuery($qb, $page, $perPage);
    }

    /**
     * Number of published stories per term id (single grouped query, no N+1).
     *
     * @return array<int, int>
     */
    public function countPublishedStoriesPerTerm(): array
    {
        $rows = $this->getEntityManager()->createQueryBuilder()
            ->select('t.id AS id, COUNT(s.id) AS total')
            ->from(Story::class, 's')
            ->join('s.'.$this->storyAssociation(), 't')
            ->andWhere('s.status = :published')
            ->setParameter('published', StoryStatus::Published)
            ->groupBy('t.id')
            ->getQuery()->getArrayResult();

        $counts = [];
        foreach ($rows as $row) {
            $counts[(int) $row['id']] = (int) $row['total'];
        }

        return $counts;
    }

    /** Stories in ANY status using the term (used before deletion). */
    public function countStoriesUsing(AbstractTaxonomy $term): int
    {
        return (int) $this->getEntityManager()->createQueryBuilder()
            ->select('COUNT(s.id)')
            ->from(Story::class, 's')
            ->join('s.'.$this->storyAssociation(), 't')
            ->andWhere('t = :term')
            ->setParameter('term', $term)
            ->getQuery()->getSingleScalarResult();
    }

    /** @return list<array{name: string, nameEn: ?string, slug: string, total: int, updatedAt: string}> active terms with published-story counts, for menus/sitemaps */
    public function findForNavigation(): array
    {
        $counts = $this->countPublishedStoriesPerTerm();
        $result = [];
        foreach ($this->findActiveOrdered() as $term) {
            $result[] = [
                'name' => $term->getName(),
                'nameEn' => $term->getNameEn(),
                'slug' => $term->getSlug(),
                'total' => $counts[$term->getId()] ?? 0,
                'updatedAt' => $term->getUpdatedAt()->format(\DATE_ATOM),
            ];
        }

        return $result;
    }
}
