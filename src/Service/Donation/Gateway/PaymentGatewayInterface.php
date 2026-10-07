<?php

declare(strict_types=1);

namespace App\Service\Donation\Gateway;

use App\Entity\Donation;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\HttpFoundation\Request;

/**
 * Extension point for a future payment gateway (Mode B, e.g. Razorpay). No gateway is required
 * for the platform to operate: the default flow is manual UPI with admin verification (Mode A).
 *
 * An implementation must verify payment status SERVER-SIDE (signed webhook/callback or API
 * lookup) before a donation may be marked verified — never trust client-side success redirects.
 */
#[AutoconfigureTag('app.payment_gateway')]
interface PaymentGatewayInterface
{
    public function getName(): string;

    public function isConfigured(): bool;

    /** Creates a payment/order at the gateway and returns what the browser needs to continue. */
    public function createPayment(Donation $donation): GatewayPaymentRequest;

    /** Validates a gateway callback/webhook signature and returns the authoritative result. */
    public function verifyCallback(Request $request): GatewayVerificationResult;
}
