<?php

declare(strict_types=1);

namespace App\Tests\Integration\Donation;

use App\DTO\DonationDeclaration;
use App\Enum\DonationStatus;
use App\Exception\DomainRuleException;
use App\Service\Donation\DonationService;
use App\Tests\Integration\IntegrationTestCase;

/** The test environment configures UPI_ID=demo@upi and UPI_PAYEE_NAME="Demo Trust". */
final class DonationServiceTest extends IntegrationTestCase
{
    private function donations(): DonationService
    {
        return $this->service(DonationService::class);
    }

    public function testInitiationCreatesUnverifiedDonationWithUpiIntent(): void
    {
        $story = $this->story('makhan-chor-bal-krishna');
        $donation = $this->donations()->initiate(10100, $story, null);

        self::assertSame(DonationStatus::Initiated, $donation->getStatus());
        self::assertSame(10100, $donation->getAmountPaise());
        self::assertMatchesRegularExpression('/^K[A-Z0-9]{5,15}$/', $donation->getReferenceCode());
        self::assertStringContainsString($donation->getReferenceCode(), $donation->getPaymentNote());

        $uri = $this->donations()->upiUri($donation);
        self::assertStringStartsWith('upi://pay?pa=demo%40upi&pn=Demo%20Trust&am=101.00&cu=INR&tn=Seva%20', $uri);
    }

    public function testAmountLimitsAreEnforced(): void
    {
        $this->expectException(DomainRuleException::class);
        $this->donations()->initiate(50, null, null); // below the ₹1 minimum
    }

    public function testDeclarationThenManualVerification(): void
    {
        $donation = $this->donations()->initiate(25100, null, null);

        $declaration = new DonationDeclaration();
        $declaration->transactionReference = ' 412345678901 ';
        $declaration->payerName = '<b>भक्त</b>';
        $declaration->anonymous = true;
        $this->donations()->declare($donation, $declaration);

        self::assertSame(DonationStatus::Declared, $donation->getStatus(), 'Declaring is not proof of payment.');
        self::assertSame('412345678901', $donation->getUpiTransactionReference());
        self::assertNull($donation->getVerifiedBy());
        self::assertNotNull($donation->getDeclaredAt());

        $admin = $this->user('admin');
        $this->donations()->verify($donation, $admin, 'Matched in bank statement');

        self::assertSame(DonationStatus::Verified, $donation->getStatus());
        self::assertSame($admin->getId(), $donation->getVerifiedBy()?->getId());
        self::assertNotNull($donation->getVerifiedAt());
    }

    public function testVerifiedDonationsCannotBeChangedByDonorOrRejected(): void
    {
        $donation = $this->donations()->initiate(5100, null, null);
        $this->donations()->verify($donation, $this->user('admin'));

        try {
            $this->donations()->declare($donation, new DonationDeclaration());
            self::fail('A verified donation must be locked for the donor.');
        } catch (DomainRuleException) {
        }

        $this->expectException(DomainRuleException::class);
        $this->donations()->reject($donation, $this->user('admin'), 'too late');
    }

    public function testDuplicateTransactionReferenceIsFlaggedForTheAdmin(): void
    {
        $first = $this->donations()->initiate(10100, null, null);
        $second = $this->donations()->initiate(10100, null, null);
        foreach ([$first, $second] as $donation) {
            $declaration = new DonationDeclaration();
            $declaration->transactionReference = '998877665544';
            $this->donations()->declare($donation, $declaration);
        }

        self::assertStringContainsString('same transaction reference', (string) $second->getAdminNotes());
        self::assertSame(DonationStatus::Declared, $second->getStatus(), 'Flagged, not auto-rejected: an admin decides.');
    }
}
