<?php

declare(strict_types=1);

namespace App\Tests\Unit\Util;

use App\Util\Duration;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Periods are written 1y1w1d1h1m1s (the owner's convention, 2026-10-26). */
final class DurationTest extends TestCase
{
    /** @return iterable<array{string, int, string}> */
    public static function periods(): iterable
    {
        yield ['1y1w1d1h1m1s', 31536000 + 604800 + 86400 + 3600 + 60 + 1, '1 year 1 week 1 day 1 hour 1 minute 1 second'];
        yield ['120d', 120 * 86400, '120 days'];
        yield ['30m', 1800, '30 minutes'];
        yield ['1m30s', 90, '1 minute 30 seconds'];
        yield ['2h', 7200, '2 hours'];
        yield ['1h30m', 5400, '1 hour 30 minutes'];
        yield ['3y', 3 * 365 * 86400, '3 years'];
        yield ['17w1d', 120 * 86400, '17 weeks 1 day'];
        yield ['15s', 15, '15 seconds'];
        yield [' 1d ', 86400, '1 day'];
    }

    #[DataProvider('periods')]
    public function testAPeriodIsReadAsWritten(string $text, int $seconds, string $words): void
    {
        $period = Duration::parse($text);
        self::assertSame(trim($text), $period->text, 'kept as written');
        self::assertSame($seconds, $period->seconds(), 'as a length a year is 365 days');
        self::assertSame($words, $period->words());
    }

    /** @return iterable<array{string, string}> */
    public static function notPeriods(): iterable
    {
        yield ['', 'Write a period'];
        yield ['0', 'Write a period'];
        yield ['1800', 'Write a period'];
        yield ['1M', 'Write a period'];
        yield ['1D', 'Write a period'];
        yield ['1mo', 'Write a period'];
        yield ['1h1d', 'Write a period'];
        yield ['1d1d', 'Write a period'];
        yield ['1 d', 'Write a period'];
        yield ['1.5h', 'Write a period'];
        yield ['-1d', 'Write a period'];
        yield ['0d', 'whole number from 1 up'];
        yield ['1d0h', 'whole number from 1 up'];
    }

    #[DataProvider('notPeriods')]
    public function testAnythingElseIsRefusedWithHowToWriteIt(string $text, string $message): void
    {
        self::assertNull(Duration::tryParse($text));
        try {
            Duration::parse($text);
            self::fail($text . ' is not a period');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString($message, $e->getMessage());
        }
    }

    public function testBeforeCountsCalendarYears(): void
    {
        $zone = new \DateTimeZone('Africa/Johannesburg');
        $at = new \DateTimeImmutable('2028-02-29 12:00:00', $zone);
        self::assertSame('2027-02-28 12:00:00', Duration::parse('1y')->before($at)->format('Y-m-d H:i:s'), 'a 29 February the year lacks is the 28th');
        self::assertSame('2024-02-29 12:00:00', Duration::parse('4y')->before($at)->format('Y-m-d H:i:s'));
        $march = new \DateTimeImmutable('2029-03-01 00:00:00', $zone);
        self::assertSame('2026-03-01 00:00:00', Duration::parse('3y')->before($march)->format('Y-m-d H:i:s'), '3y spans a leap day: three calendar years, not 1095 days');
        self::assertSame('2029-02-28 23:58:30', Duration::parse('1m30s')->before($march)->format('Y-m-d H:i:s'));
        self::assertSame('2028-02-21 23:00:00', Duration::parse('1y1w1h')->before($march)->format('Y-m-d H:i:s'));
    }

    public function testASettingFallsBackToItsDefaultAndNeverGoesBelowItsShortest(): void
    {
        self::assertSame('90d', Duration::setting('90d', '1y', '1w')->text);
        self::assertSame('1y', Duration::setting(null, '1y', '1w')->text, 'not set');
        self::assertSame('1y', Duration::setting('365', '1y', '1w')->text, 'not a period');
        self::assertSame('1w', Duration::setting('1d', '1y', '1w')->text, 'too short');
    }
}
