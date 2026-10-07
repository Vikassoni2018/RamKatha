<?php

declare(strict_types=1);

namespace App\Tests\Unit\Donation;

use App\Entity\Donation;
use App\Enum\DonationStatus;
use PHPUnit\Framework\TestCase;

final class DonationStatusTest extends TestCase
{
    public function testStaticUpiPaymentsCanOnlyBeVerifiedByReview(): void
    {
        self::assertTrue(DonationStatus::Initiated->canTransitionTo(DonationStatus::Declared));
        self::assertTrue(DonationStatus::Declared->canTransitionTo(DonationStatus::Verified));
        self::assertTrue(DonationStatus::Declared->canTransitionTo(DonationStatus::Rejected));
        self::assertTrue(DonationStatus::Rejected->canTransitionTo(DonationStatus::Verified), 'A wrongly rejected payment can be corrected.');
        self::assertFalse(DonationStatus::Verified->canTransitionTo(DonationStatus::Declared));
        self::assertFalse(DonationStatus::Verified->canTransitionTo(DonationStatus::Verified));
        self::assertFalse(DonationStatus::Refunded->canTransitionTo(DonationStatus::Verified));
    }

    public function testDonationRejectsNonPositiveAmounts(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Donation(0, 'KTEST', 'Seva KTEST');
    }

    public function testDonationStartsAsInitiatedWithoutVerifier(): void
    {
        $donation = new Donation(10100, 'KTEST', 'Seva KTEST');
        self::assertSame(DonationStatus::Initiated, $donation->getStatus());
        self::assertNull($donation->getVerifiedBy());
        self::assertSame('INR', $donation->getCurrency());
    }
}
