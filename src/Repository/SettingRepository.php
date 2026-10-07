<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Setting;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Setting>
 */
class SettingRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Setting::class);
    }

    /** @return array<string, mixed> name => decoded value */
    public function findAllAsArray(): array
    {
        $values = [];
        foreach ($this->findAll() as $setting) {
            try {
                $values[$setting->getName()] = $setting->getValue();
            } catch (\JsonException) {
                // Ignore corrupted values; defaults apply.
            }
        }

        return $values;
    }

    public function findOneByName(string $name): ?Setting
    {
        return $this->findOneBy(['name' => $name]);
    }
}
