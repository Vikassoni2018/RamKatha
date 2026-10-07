<?php

declare(strict_types=1);

namespace App\Service\System;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\ErrorDetailsStamp;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;
use Symfony\Component\Messenger\Transport\Receiver\ListableReceiverInterface;
use Symfony\Component\Messenger\Transport\Receiver\MessageCountAwareInterface;
use Symfony\Component\Messenger\Transport\TransportInterface;

/**
 * Admin view on the Messenger failure transport (Doctrine "failed" queue): list, retry, remove.
 * CLI equivalents: messenger:failed:show / messenger:failed:retry / messenger:failed:remove.
 */
final class FailedMessageService
{
    public function __construct(
        #[Autowire(service: 'messenger.transport.failed')]
        private readonly TransportInterface $failedTransport,
        #[Autowire(service: 'messenger.transport.async')]
        private readonly TransportInterface $asyncTransport,
        private readonly MessageBusInterface $bus,
    ) {
    }

    public function count(): ?int
    {
        return $this->failedTransport instanceof MessageCountAwareInterface ? $this->failedTransport->getMessageCount() : null;
    }

    public function pendingCount(): ?int
    {
        try {
            return $this->asyncTransport instanceof MessageCountAwareInterface ? $this->asyncTransport->getMessageCount() : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /** @return list<array{id: mixed, class: string, error: ?string, errorClass: ?string, failedAt: ?\DateTimeInterface, retries: int}> */
    public function list(int $limit = 50): array
    {
        if (!$this->failedTransport instanceof ListableReceiverInterface) {
            return [];
        }
        $rows = [];
        foreach ($this->failedTransport->all($limit) as $envelope) {
            $rows[] = $this->describe($envelope);
        }

        return $rows;
    }

    public function retry(mixed $id): bool
    {
        $envelope = $this->find($id);
        if (null === $envelope) {
            return false;
        }
        // Re-dispatch the original message as a fresh envelope and drop the failed copy.
        $this->bus->dispatch($envelope->getMessage());
        $this->failedTransport->reject($envelope);

        return true;
    }

    public function remove(mixed $id): bool
    {
        $envelope = $this->find($id);
        if (null === $envelope) {
            return false;
        }
        $this->failedTransport->reject($envelope);

        return true;
    }

    private function find(mixed $id): ?Envelope
    {
        return $this->failedTransport instanceof ListableReceiverInterface ? $this->failedTransport->find($id) : null;
    }

    /** @return array{id: mixed, class: string, error: ?string, errorClass: ?string, failedAt: ?\DateTimeInterface, retries: int} */
    private function describe(Envelope $envelope): array
    {
        /** @var ErrorDetailsStamp|null $error */
        $error = $envelope->last(ErrorDetailsStamp::class);
        /** @var RedeliveryStamp|null $redelivery */
        $redelivery = $envelope->last(RedeliveryStamp::class);

        return [
            'id' => $envelope->last(TransportMessageIdStamp::class)?->getId(),
            'class' => (new \ReflectionClass($envelope->getMessage()))->getShortName(),
            'error' => $error?->getExceptionMessage(),
            'errorClass' => $error?->getExceptionClass(),
            'failedAt' => $redelivery?->getRedeliveredAt(),
            'retries' => $redelivery?->getRetryCount() ?? 0,
        ];
    }
}
