<?php

declare(strict_types=1);

namespace App\Tests\Unit\Util;

use App\Util\Units;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class UnitsTest extends TestCase
{
    /**
     * @return iterable<array{string, float}>
     */
    public static function distances(): iterable
    {
        yield ['10', 10000.0];
        yield ['10k', 10000.0];
        yield ['10 km', 10000.0];
        yield ['21,1', 21100.0];
        yield ['5000m', 5000.0];
        yield ['semi', 21097.5];
        yield ['Marathon', 42195.0];
    }

    #[DataProvider('distances')]
    public function testParseDistance(string $input, float $expected): void
    {
        self::assertSame($expected, Units::parseDistance($input));
    }

    /**
     * @return iterable<array{string, int}>
     */
    public static function durations(): iterable
    {
        yield ['45:00', 2700];
        yield ['1:45:00', 6300];
        yield ['3h30', 12600];
        yield ['3h', 10800];
        yield ['50min', 3000];
        yield ['50', 3000];
    }

    #[DataProvider('durations')]
    public function testParseDuration(string $input, int $expected): void
    {
        self::assertSame($expected, Units::parseDuration($input));
    }

    public function testParseDateAcceptsBothFormatsAndRejectsPast(): void
    {
        $today = new \DateTimeImmutable('2026-10-16');

        self::assertSame('2026-12-15', Units::parseDate('2026-12-15', $today)->format('Y-m-d'));
        self::assertSame('2026-12-15', Units::parseDate('15/12/2026', $today)->format('Y-m-d'));

        $this->expectException(\InvalidArgumentException::class);
        Units::parseDate('2026-01-01', $today);
    }

    public function testInvalidInputIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Units::parseDuration('vite');
    }

    public function testParseNumberHandlesFrenchAndEnglishNotations(): void
    {
        self::assertSame(1234.5, Units::parseNumber('1 234,5'));
        self::assertSame(1234.5, Units::parseNumber('1,234.5'));
        self::assertSame(10.02, Units::parseNumber('10.02'));
        self::assertNull(Units::parseNumber(''));
    }

    public function testFormatting(): void
    {
        self::assertSame('45:00', Units::formatDuration(2700));
        self::assertSame('1:45:00', Units::formatDuration(6300));
        self::assertSame('5:30/km', Units::formatPace(330.0));
        self::assertSame('10 km', Units::formatDistance(10000.0));
        self::assertSame('Semi-marathon', Units::formatDistance(21097.5));
    }
}
