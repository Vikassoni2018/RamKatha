<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * A static UPI QR / intent cannot prove that a payment happened. Therefore:
 *  - initiated: the visitor opened the UPI intent / QR (no payment proof)
 *  - declared:  the visitor says they paid and optionally gave a reference
 *  - verified:  an admin confirmed the payment in the bank statement (or a future gateway confirmed it)
 *  - rejected:  an admin could not match the payment
 *  - refunded:  reserved for future gateway integrations
 */
enum DonationStatus: string
{
    case Initiated = 'initiated';
    case Declared = 'declared';
    case Verified = 'verified';
    case Rejected = 'rejected';
    case Refunded = 'refunded';

    /** English label for the (English-only) admin panel. */
    public function label(): string
    {
        return match ($this) {
            self::Initiated => 'Initiated',
            self::Declared => 'Payment declared',
            self::Verified => 'Verified',
            self::Rejected => 'Rejected',
            self::Refunded => 'Refunded',
        };
    }

    /**
     * Translation key (messages domain) of the label shown to the donor on the public site, in the
     * page language: {{ donation.status.translationKey|trans }}.
     */
    public function translationKey(): string
    {
        return 'donation.status.'.$this->value;
    }

    /** Hindi original of the donor-facing label (public pages use translationKey()). */
    public function hindiLabel(): string
    {
        return match ($this) {
            self::Initiated => 'प्रारंभ',
            self::Declared => 'भुगतान की सूचना दी गई',
            self::Verified => 'सत्यापित',
            self::Rejected => 'अस्वीकृत',
            self::Refunded => 'वापस किया गया',
        };
    }

    public function badge(): string
    {
        return match ($this) {
            self::Initiated => 'neutral',
            self::Declared => 'warning',
            self::Verified => 'success',
            self::Rejected => 'danger',
            self::Refunded => 'muted',
        };
    }

    /** @return DonationStatus[] */
    public function allowedNext(): array
    {
        return match ($this) {
            self::Initiated => [self::Declared, self::Verified, self::Rejected],
            self::Declared => [self::Verified, self::Rejected],
            self::Rejected => [self::Verified],
            self::Verified => [self::Refunded],
            self::Refunded => [],
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return \in_array($next, $this->allowedNext(), true);
    }
}
