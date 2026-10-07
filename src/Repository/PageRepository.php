<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Page;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Page>
 */
class PageRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Page::class);
    }

    public function findPublishedBySlug(string $slug): ?Page
    {
        return $this->findOneBy(['slug' => $slug, 'isPublished' => true]);
    }

    /** @return list<array{title: string, titleEn: ?string, slug: string}> */
    public function findFooterLinks(): array
    {
        return $this->createQueryBuilder('p')
            ->select('p.title AS title, p.titleEn AS titleEn, p.slug AS slug')
            ->andWhere('p.isPublished = true')
            ->andWhere('p.showInFooter = true')
            ->orderBy('p.position', 'ASC')
            ->getQuery()->getArrayResult();
    }

    /** @return list<Page> */
    public function findAllOrdered(): array
    {
        return $this->createQueryBuilder('p')->orderBy('p.position', 'ASC')->addOrderBy('p.title', 'ASC')->getQuery()->getResult();
    }

    /** @return list<array{slug: string, updatedAt: \DateTimeImmutable, hasEnglish: int|string}> */
    public function findForSitemap(): array
    {
        return $this->createQueryBuilder('p')
            ->select('p.slug AS slug, p.updatedAt AS updatedAt')
            ->addSelect('(CASE WHEN p.titleEn IS NOT NULL AND p.bodyEn IS NOT NULL THEN 1 ELSE 0 END) AS hasEnglish')
            ->andWhere('p.isPublished = true')
            ->getQuery()->getArrayResult();
    }
}
