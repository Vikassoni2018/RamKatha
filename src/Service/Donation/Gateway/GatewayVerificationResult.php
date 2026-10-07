<?php

declare(strict_types=1);

namespace App\Service\Donation\Gateway;

final class GatewayVerificationResult
{
    public function __construct(
        public readonly bool $signatureValid,
        public readonly string $gatewayPaymentId,
        public readonly bool $paid,
        public readonly ?int $amountPaise = null,
        public readonly ?string $error = null,
    ) {
    }
}
