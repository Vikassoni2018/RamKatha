<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Source verification state. Only "verified" (set explicitly by an authorized reviewer)
 * results in the public "Source Verified" badge.
 */
enum VerificationStatus: string
{
    case Unverified = 'unverified';
    case Verified = 'verified';
    case NeedsCorrection = 'needs_correction';

    public function label(): string
    {
        return match ($this) {
            self::Unverified => 'Not verified',
            self::Verified => 'Source verified',
            self::NeedsCorrection => 'Needs correction',
        };
    }

    public function badge(): string
    {
        return match ($this) {
            self::Unverified => 'neutral',
            self::Verified => 'success',
            self::NeedsCorrection => 'danger',
        };
    }
}
