<?php

declare(strict_types=1);

namespace App\Tests\Unit\Http;

use App\Http\Htmx;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

/** Which htmx requests ask for a region, and where an htmx action returns to. */
final class HtmxTest extends TestCase
{
    public function testARegionIsAskedForOnlyByAPartialHtmxRequest(): void
    {
        $partial = ['HTTP_HX_REQUEST' => 'true', 'HTTP_HX_REQUEST_TYPE' => 'partial', 'HTTP_HX_TARGET' => 'div#subscriber-results'];
        self::assertSame('subscriber-results', Htmx::region(self::request($partial)));
        self::assertNull(Htmx::region(self::request()), 'a page load');
        self::assertNull(Htmx::region(self::request(['HTTP_HX_REQUEST_TYPE' => 'full'] + $partial)), 'htmx asking for the page');
        self::assertNull(Htmx::region(self::request(['HTTP_HX_HISTORY_RESTORE_REQUEST' => 'true'] + $partial)), 'Back: the whole page');
        self::assertNull(Htmx::region(self::request(['HTTP_HX_REQUEST' => 'false'] + $partial)));
        self::assertNull(Htmx::region(self::request(['HTTP_HX_TARGET' => 'div'] + $partial)), 'a target without an id');
        self::assertNull(Htmx::region(self::request(['HTTP_HX_TARGET' => 'div#a"b'] + $partial)), 'not an id this site uses');
    }

    public function testARegionRefreshesItselfWhenItIsBothSourceAndTarget(): void
    {
        $poll = ['HTTP_HX_REQUEST' => 'true', 'HTTP_HX_REQUEST_TYPE' => 'partial', 'HTTP_HX_TARGET' => 'div#send-job', 'HTTP_HX_SOURCE' => 'div#send-job'];
        self::assertTrue(Htmx::refreshesItself(self::request($poll)));
        self::assertFalse(Htmx::refreshesItself(self::request(['HTTP_HX_SOURCE' => 'form#filter'] + $poll)), 'the filter form');
        self::assertFalse(Htmx::refreshesItself(self::request(['HTTP_HX_REQUEST_TYPE' => 'full'] + $poll)));
    }

    public function testAnActionReturnsToThePageItWasSentFromOnThisSiteOnly(): void
    {
        $htmx = ['HTTP_HX_REQUEST' => 'true', 'HTTP_HOST' => 'lists.example.org'];
        self::assertSame('/roles/3/2?q=ann', Htmx::currentPage(self::request(['HTTP_HX_CURRENT_URL' => 'https://lists.example.org/roles/3/2?q=ann#members'] + $htmx)));
        self::assertNull(Htmx::currentPage(self::request(['HTTP_HX_CURRENT_URL' => 'https://elsewhere.example/roles'] + $htmx)), 'another site');
        self::assertNull(Htmx::currentPage(self::request(['HTTP_HX_CURRENT_URL' => '/roles'] + $htmx)), 'not a full address');
        self::assertNull(Htmx::currentPage(self::request(['HTTP_HX_CURRENT_URL' => 'https://lists.example.org/roles', 'HTTP_HX_REQUEST' => 'false'] + $htmx)), 'not htmx');
        self::assertNull(Htmx::currentPage(self::request(['HTTP_HOST' => 'lists.example.org'])));
    }

    /** @param array<string, string> $server */
    private static function request(array $server = []): Request
    {
        return Request::create('/', 'GET', server: $server);
    }
}
