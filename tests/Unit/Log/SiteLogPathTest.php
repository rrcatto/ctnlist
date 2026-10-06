<?php

declare(strict_types=1);

namespace App\Tests\Unit\Log;

use App\Log\SiteLog;
use PHPUnit\Framework\TestCase;

final class SiteLogPathTest extends TestCase
{
    public function testCredentialsInPathsAreMasked(): void
    {
        self::assertSame('/unsubscribe-link/u/NEWS/m/[signature]', SiteLog::loggablePath('/unsubscribe-link/u/NEWS/m/c2lnbmF0dXJl'));
        self::assertSame('/unsubscribe/u/NEWS/m', SiteLog::loggablePath('/unsubscribe/u/NEWS/m'), 'other paths unchanged');
        self::assertSame('/auth/verify', SiteLog::loggablePath('/auth/verify'));
    }
}
