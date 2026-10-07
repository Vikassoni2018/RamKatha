<?php

declare(strict_types=1);

namespace App\Service\Audit;

use App\Entity\AuditLog;
use App\Entity\User;
use App\Enum\AuditAction;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Records important administrative actions. Persisted together with the caller's unit of work
 * (call flush yourself, or pass $flush=true). Sensitive keys are stripped from metadata.
 */
final class AuditLogger
{
    private const SENSITIVE_KEYS = ['password', 'plainpassword', 'token', 'access_token', 'accesstoken', 'secret', 'api_key', 'apikey', 'pin', 'otp', 'authorization'];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Security $security,
        private readonly RequestStack $requestStack,
        #[Autowire(env: 'bool:AUDIT_LOG_STORE_IP')]
        private readonly bool $storeIp = true,
    ) {
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public function log(AuditAction $action, ?object $entity = null, array $metadata = [], bool $flush = false, ?User $actor = null): AuditLog
    {
        $actor ??= $this->currentUser();
        [$entityType, $entityId] = $this->describeEntity($entity);

        $log = new AuditLog(
            action: $action,
            actor: $actor,
            actorIdentifier: $actor?->getUserIdentifier() ?? ('cli' === \PHP_SAPI ? 'system (cli)' : 'system'),
            entityType: $entityType,
            entityId: $entityId,
            metadata: [] === $metadata ? null : $this->scrub($metadata),
            ipAddress: $this->storeIp ? $this->requestStack->getMainRequest()?->getClientIp() : null,
        );
        $this->em->persist($log);
        if ($flush) {
            $this->em->flush();
        }

        return $log;
    }

    private function currentUser(): ?User
    {
        $user = $this->security->getUser();

        return $user instanceof User ? $user : null;
    }

    /** @return array{0: ?string, 1: ?string} */
    private function describeEntity(?object $entity): array
    {
        if (null === $entity) {
            return [null, null];
        }
        $class = (new \ReflectionClass($entity))->getShortName();
        // Doctrine proxies extend the entity class.
        if (str_contains($class, 'Proxy')) {
            $class = (new \ReflectionClass(get_parent_class($entity) ?: $entity))->getShortName();
        }
        $id = method_exists($entity, 'getId') ? $entity->getId() : null;

        return [$class, null !== $id ? (string) $id : null];
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private function scrub(array $data): array
    {
        foreach ($data as $key => $value) {
            if (\in_array(strtolower(str_replace('-', '_', (string) $key)), self::SENSITIVE_KEYS, true)) {
                $data[$key] = '[redacted]';
            } elseif (\is_array($value)) {
                $data[$key] = $this->scrub($value);
            } elseif ($value instanceof \BackedEnum) {
                $data[$key] = $value->value;
            } elseif ($value instanceof \DateTimeInterface) {
                $data[$key] = $value->format(\DATE_ATOM);
            } elseif (\is_object($value)) {
                $data[$key] = method_exists($value, '__toString') ? (string) $value : $value::class;
            } elseif (\is_string($value) && mb_strlen($value) > 500) {
                $data[$key] = mb_substr($value, 0, 500).'…';
            }
        }

        return $data;
    }
}
