<?php

declare(strict_types=1);

namespace App\Service\Donation\Gateway;

final class GatewayPaymentRequest
{
    /**
     * @param array<string, scalar> $clientParameters parameters for a client-side checkout widget
     */
    public function __construct(
        public readonly string $gatewayPaymentId,
        public readonly ?string $redirectUrl = null,
        public readonly array $clientParameters = [],
    ) {
    }
}
