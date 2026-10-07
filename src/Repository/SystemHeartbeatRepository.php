<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\SystemHeartbeat;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SystemHeartbeat>
 */
class SystemHeartbeatRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SystemHeartbeat::class);
    }

    /** @return array<string, SystemHeartbeat> */
    public function findAllIndexed(): array
    {
        $result = [];
        foreach ($this->findBy([], ['name' => 'ASC']) as $heartbeat) {
            $result[$heartbeat->getName()] = $heartbeat;
        }

        return $result;
    }
}
