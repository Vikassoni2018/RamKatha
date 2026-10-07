<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\SystemHeartbeatRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/** Last execution of a cron / scheduled job, shown on the System Status page. */
#[ORM\Entity(repositoryClass: SystemHeartbeatRepository::class)]
#[ORM\Table(name: 'system_heartbeat')]
#[ORM\UniqueConstraint(name: 'uniq_heartbeat_name', columns: ['name'])]
class SystemHeartbeat
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $lastRunAt;

    #[ORM\Column(length: 20)]
    private string $lastStatus = 'ok';

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $lastMessage = null;

    #[ORM\Column(nullable: true)]
    private ?int $lastDurationMs = null;

    public function __construct(
        #[ORM\Column(length: 100)]
        private string $name,
    ) {
        $this->lastRunAt = new \DateTimeImmutable();
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function record(string $status, ?string $message, int $durationMs, \DateTimeImmutable $at): void
    {
        $this->lastStatus = $status;
        $this->lastMessage = null !== $message ? mb_substr($message, 0, 2000) : null;
        $this->lastDurationMs = $durationMs;
        $this->lastRunAt = $at;
    }

    public function getLastRunAt(): \DateTimeImmutable
    {
        return $this->lastRunAt;
    }

    public function getLastStatus(): string
    {
        return $this->lastStatus;
    }

    public function getLastMessage(): ?string
    {
        return $this->lastMessage;
    }

    public function getLastDurationMs(): ?int
    {
        return $this->lastDurationMs;
    }
}
