<?php

declare(strict_types=1);

namespace App\Tests\Unit\Util;

use App\Util\Duration;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DurationTest extends TestCase
{
    /** @return iterable<array{int, string}> */
    public static function durations(): iterable
    {
        yield [1800, '30 minutes'];
        yield [60, '1 minute'];
        yield [61, '2 minutes'];
        yield [3600, '1 hour'];
        yield [7200, '2 hours'];
        yield [5400, '1 hour 30 minutes'];
        yield [0, '1 minute'];
    }

    #[DataProvider('durations')]
    public function testDescribe(int $seconds, string $words): void
    {
        self::assertSame($words, Duration::describe($seconds));
    }
}
