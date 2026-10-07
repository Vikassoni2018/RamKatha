<?php

declare(strict_types=1);

namespace App\Tests\Unit\Donation;

use App\Service\Donation\MoneyFormatter;
use App\Service\Donation\UpiPaymentUriBuilder;
use PHPUnit\Framework\TestCase;

final class UpiPaymentUriBuilderTest extends TestCase
{
    private UpiPaymentUriBuilder $builder;

    protected function setUp(): void
    {
        $this->builder = new UpiPaymentUriBuilder(new MoneyFormatter());
    }

    public function testBuildsEncodedNpciUri(): void
    {
        $uri = $this->builder->build('trust.name@okbank', 'Shri Katha Trust', 10100, 'Seva KAB12CD3');

        self::assertStringStartsWith('upi://pay?', $uri);
        parse_str((string) parse_url($uri, \PHP_URL_QUERY), $params);
        self::assertSame('trust.name@okbank', $params['pa']);
        self::assertSame('Shri Katha Trust', $params['pn']);
        self::assertSame('101.00', $params['am']);
        self::assertSame('INR', $params['cu']);
        self::assertSame('Seva KAB12CD3', $params['tn']);
        // RFC 3986 encoding: spaces must be %20, not "+".
        self::assertStringContainsString('pn=Shri%20Katha%20Trust', $uri);
        self::assertStringNotContainsString('tr=', $uri, 'Merchant-only "tr" must not be used for personal VPAs.');
    }

    public function testFractionalAmountsUseTwoDecimals(): void
    {
        $uri = $this->builder->build('demo@upi', 'X', 15150);
        self::assertStringContainsString('am=151.50', $uri);
    }

    public function testNoteIsSanitisedToSafeAscii(): void
    {
        self::assertSame('Seva KX alert1', $this->builder->sanitizeNote('Seva <KX> & "alert(1)"'));
        self::assertLessThanOrEqual(UpiPaymentUriBuilder::MAX_NOTE_LENGTH, \strlen($this->builder->sanitizeNote(str_repeat('a', 200))));
    }

    public function testPayeeNameKeepsDevanagariButStripsSymbols(): void
    {
        self::assertSame('कथा ट्रस्ट', $this->builder->sanitizeName('कथा <ट्रस्ट>'));
    }

    public function testRejectsInvalidVpa(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->builder->build('not-a-vpa', 'X', 100);
    }

    public function testRejectsNonPositiveAmount(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->builder->build('demo@upi', 'X', 0);
    }
}
