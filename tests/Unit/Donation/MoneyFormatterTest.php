<?php

declare(strict_types=1);

namespace App\Tests\Unit\Donation;

use App\Service\Donation\MoneyFormatter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MoneyFormatterTest extends TestCase
{
    /** @return iterable<array{int, string}> */
    public static function amounts(): iterable
    {
        yield [5100, '₹51'];
        yield [10100, '₹101'];
        yield [15150, '₹151.50'];
        yield [100000, '₹1,000'];
        yield [12500000, '₹1,25,000'];
        yield [1234567890, '₹1,23,45,678.90'];
        yield [5, '₹0.05'];
    }

    #[DataProvider('amounts')]
    public function testFormatsIndianRupees(int $paise, string $expected): void
    {
        self::assertSame($expected, (new MoneyFormatter())->format($paise));
    }

    public function testParsesUserInputWithoutFloatingPoint(): void
    {
        $money = new MoneyFormatter();
        self::assertSame(10100, $money->parseToPaise('101'));
        self::assertSame(10150, $money->parseToPaise('101.5'));
        self::assertSame(100150, $money->parseToPaise('1,001.50'));
        self::assertSame(29, $money->parseToPaise('0.29'), '0.29 must not become 28 through float rounding');
        self::assertNull($money->parseToPaise('abc'));
        self::assertNull($money->parseToPaise('10.123'));
        self::assertNull($money->parseToPaise('-5'));
    }

    public function testDecimalString(): void
    {
        self::assertSame('101.00', (new MoneyFormatter())->toDecimalString(10100));
    }
}
