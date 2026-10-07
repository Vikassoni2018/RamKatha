<?php

declare(strict_types=1);

namespace App\Service\Donation\Gateway;

use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/** Holds optional gateway adapters (none are bundled). */
final class PaymentGatewayRegistry
{
    /** @var array<string, PaymentGatewayInterface> */
    private array $gateways = [];

    /** @param iterable<PaymentGatewayInterface> $gateways */
    public function __construct(#[AutowireIterator('app.payment_gateway')] iterable $gateways)
    {
        foreach ($gateways as $gateway) {
            $this->gateways[$gateway->getName()] = $gateway;
        }
    }

    public function getActive(): ?PaymentGatewayInterface
    {
        foreach ($this->gateways as $gateway) {
            if ($gateway->isConfigured()) {
                return $gateway;
            }
        }

        return null;
    }

    public function has(string $name): bool
    {
        return isset($this->gateways[$name]);
    }

    public function get(string $name): ?PaymentGatewayInterface
    {
        return $this->gateways[$name] ?? null;
    }
}
