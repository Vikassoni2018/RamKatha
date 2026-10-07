<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\MediaFile;
use App\Pagination\Paginator;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<MediaFile>
 */
class MediaFileRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MediaFile::class);
    }

    public function findOneByUuid(Uuid $uuid): ?MediaFile
    {
        return $this->findOneBy(['uuid' => $uuid]);
    }

    public function findOneByChecksum(string $checksum): ?MediaFile
    {
        return $this->findOneBy(['checksum' => $checksum]);
    }

    /** @return Paginator<MediaFile> */
    public function searchPaginated(?string $query, int $page, int $perPage = 24): Paginator
    {
        $qb = $this->createQueryBuilder('m')
            ->leftJoin('m.uploadedBy', 'u')->addSelect('u')
            ->orderBy('m.createdAt', 'DESC');

        if (null !== $query && '' !== trim($query)) {
            $qb->andWhere('m.originalName LIKE :q OR m.altText LIKE :q')
                ->setParameter('q', '%'.addcslashes(trim($query), '%_').'%');
        }

        return Paginator::fromQuery($qb, $page, $perPage);
    }

    /** @return list<MediaFile> */
    public function findRecent(int $limit = 30): array
    {
        return $this->createQueryBuilder('m')
            ->orderBy('m.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()->getResult();
    }
}
