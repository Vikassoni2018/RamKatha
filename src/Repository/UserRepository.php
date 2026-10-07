<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\User;
use App\Pagination\Paginator;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\PasswordUpgraderInterface;

/**
 * @extends ServiceEntityRepository<User>
 */
class UserRepository extends ServiceEntityRepository implements PasswordUpgraderInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, User::class);
    }

    public function upgradePassword(PasswordAuthenticatedUserInterface $user, string $newHashedPassword): void
    {
        if (!$user instanceof User) {
            throw new UnsupportedUserException(\sprintf('Instances of "%s" are not supported.', $user::class));
        }

        $user->setPassword($newHashedPassword);
        $this->getEntityManager()->flush();
    }

    public function findOneByEmail(string $email): ?User
    {
        return $this->findOneBy(['email' => mb_strtolower(trim($email))]);
    }

    /** @return Paginator<User> */
    public function searchPaginated(?string $query, ?string $role, ?string $state, int $page, int $perPage = 25): Paginator
    {
        $qb = $this->createQueryBuilder('u')->orderBy('u.createdAt', 'DESC');

        if (null !== $query && '' !== trim($query)) {
            $qb->andWhere('u.email LIKE :q OR u.displayName LIKE :q')
                ->setParameter('q', '%'.addcslashes(trim($query), '%_').'%');
        }
        if (null !== $role && '' !== $role) {
            // Roles are stored as a JSON array of strings.
            $qb->andWhere('u.roles LIKE :role')->setParameter('role', '%"'.addcslashes($role, '%_').'"%');
        }
        if ('active' === $state) {
            $qb->andWhere('u.isActive = true');
        } elseif ('inactive' === $state) {
            $qb->andWhere('u.isActive = false');
        } elseif ('unverified' === $state) {
            $qb->andWhere('u.isVerified = false');
        }

        return Paginator::fromQuery($qb, $page, $perPage);
    }

    public function countAll(): int
    {
        return (int) $this->createQueryBuilder('u')->select('COUNT(u.id)')->getQuery()->getSingleScalarResult();
    }

    public function countCreatedSince(\DateTimeImmutable $since): int
    {
        return (int) $this->createQueryBuilder('u')
            ->select('COUNT(u.id)')
            ->andWhere('u.createdAt >= :since')
            ->setParameter('since', $since)
            ->getQuery()->getSingleScalarResult();
    }

    public function countWithRole(string $role): int
    {
        return (int) $this->createQueryBuilder('u')
            ->select('COUNT(u.id)')
            ->andWhere('u.roles LIKE :role')
            ->andWhere('u.isActive = true')
            ->setParameter('role', '%"'.$role.'"%')
            ->getQuery()->getSingleScalarResult();
    }
}
