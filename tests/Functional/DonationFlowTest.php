<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Donation;
use App\Enum\DonationStatus;

/** Voluntary UPI support with manual verification (UPI_ID=demo@upi in the test environment). */
final class DonationFlowTest extends AppWebTestCase
{
    /** @return array<string, mixed> */
    private function initiate(string $amount = '101'): array
    {
        return (array) $this->json('POST', '/support/initiate', ['amount' => $amount, 'story' => $this->story('makhan-chor-bal-krishna')->getUuid()->toRfc4122()]);
    }

    private function donationFromDeclareUrl(string $declareUrl): Donation
    {
        $em = $this->em();
        $em->clear();
        $uuid = basename($declareUrl);

        return $em->getRepository(Donation::class)->createQueryBuilder('d')
            ->andWhere('d.uuid = :uuid')->setParameter('uuid', $uuid, 'uuid')
            ->getQuery()->getSingleResult();
    }

    public function testDonorFlowEndsInManualVerification(): void
    {
        $data = $this->initiate();
        self::assertResponseStatusCodeSame(201);
        self::assertSame('₹101', $data['amountFormatted']);
        self::assertStringStartsWith('upi://pay?pa=demo%40upi&pn=Demo%20Trust&am=101.00&cu=INR&tn=Seva%20', $data['upiUri']);
        self::assertStringContainsString($data['reference'], $data['upiUri']);
        self::assertMatchesRegularExpression('#/media/qr/[a-f0-9]{40}\.svg$#', $data['qrUrl']);
        self::assertSame(DonationStatus::Initiated, $this->donationFromDeclareUrl($data['declareUrl'])->getStatus());

        // Donor declares the payment (no PIN/OTP is ever asked).
        $crawler = $this->client->request('GET', $data['declareUrl']);
        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('input[type="password"]'));
        $form = $crawler->filter('form[name="donation_declaration"]')->form([
            'donation_declaration[transactionReference]' => '412345678901',
            'donation_declaration[payerName]' => 'डेमो भक्त',
        ]);
        $this->client->submit($form);
        self::assertResponseRedirects($data['declareUrl'].'/thanks');

        $donation = $this->donationFromDeclareUrl($data['declareUrl']);
        self::assertSame(DonationStatus::Declared, $donation->getStatus(), 'A declaration is not a verified payment.');
        self::assertSame('412345678901', $donation->getUpiTransactionReference());

        // Only admins may verify.
        foreach (['user', 'editor'] as $name) {
            $this->loginAs($name);
            $this->post('/admin/donations/'.$donation->getId().'/verify');
            self::assertResponseStatusCodeSame(403, $name.' must not verify donations.');
        }

        $admin = $this->loginAs('admin');
        $this->post('/admin/donations/'.$donation->getId().'/verify', ['note' => 'Matched bank statement']);
        self::assertResponseRedirects('/admin/donations/'.$donation->getId());

        $verified = $this->donationFromDeclareUrl($data['declareUrl']);
        self::assertSame(DonationStatus::Verified, $verified->getStatus());
        self::assertSame($admin->getId(), $verified->getVerifiedBy()?->getId());

        // The donor can no longer change a verified donation.
        $this->client->request('GET', $data['declareUrl']);
        self::assertResponseRedirects($data['declareUrl'].'/thanks');
    }

    public function testInvalidAmountsAreRejected(): void
    {
        foreach (['abc', '0', '-10', '10.555'] as $amount) {
            $this->initiate($amount);
            self::assertResponseStatusCodeSame(422, $amount);
        }
    }

    public function testCrossSiteInitiationIsRejected(): void
    {
        $this->json('POST', '/support/initiate', ['amount' => '51'], ['HTTP_SEC_FETCH_SITE' => 'cross-site']);

        self::assertResponseStatusCodeSame(403);
    }

    public function testAdminActionsRequireCsrf(): void
    {
        $data = $this->initiate();
        $donation = $this->donationFromDeclareUrl($data['declareUrl']);
        $this->loginAs('admin');

        $this->client->request('POST', '/admin/donations/'.$donation->getId().'/verify', ['_csrf_token' => 'csrf-token'], [], ['HTTP_SEC_FETCH_SITE' => 'cross-site']);
        self::assertResponseStatusCodeSame(400);
        self::assertSame(DonationStatus::Initiated, $this->donationFromDeclareUrl($data['declareUrl'])->getStatus());
    }
}
