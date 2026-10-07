<?php

declare(strict_types=1);

namespace App\DTO;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * What a donor may tell us after paying in their UPI app. All fields are optional.
 * NEVER add fields for UPI PIN, OTP, card or bank credentials.
 * Validation messages are translation keys of the "validators" domain.
 */
final class DonationDeclaration
{
    #[Assert\Length(min: 6, max: 35, minMessage: 'support.declaration.reference_too_short', maxMessage: 'support.declaration.reference_too_long')]
    #[Assert\Regex(pattern: '/^[A-Za-z0-9\-]+$/', message: 'support.declaration.reference_characters')]
    public ?string $transactionReference = null;

    #[Assert\Length(max: 120)]
    public ?string $payerName = null;

    #[Assert\Length(max: 12)]
    #[Assert\Regex(pattern: '/^\d{1,9}(\.\d{1,2})?$/', message: 'support.declaration.amount_invalid')]
    public ?string $amount = null;

    #[Assert\LessThanOrEqual('today', message: 'support.declaration.payment_date_future')]
    public ?\DateTimeImmutable $paymentDate = null;

    #[Assert\Email(message: 'support.declaration.email_invalid')]
    #[Assert\Length(max: 180)]
    public ?string $email = null;

    #[Assert\Regex(pattern: '/^\+?[0-9 \-]{7,20}$/', message: 'support.declaration.phone_invalid')]
    public ?string $phone = null;

    #[Assert\Length(max: 1000)]
    public ?string $message = null;

    public bool $anonymous = false;
}
