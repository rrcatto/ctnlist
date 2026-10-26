<?php

declare(strict_types=1);

namespace App\Tests\Unit\Http;

use App\Http\BotCheck;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class BotCheckTest extends TestCase
{
    /** @return iterable<string, array{string}> what the form bots of October 2026 called themselves */
    public static function botAgents(): iterable
    {
        yield 'Chrome 41' => ['Mozilla/5.0 (Windows NT 6.1; WOW64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/41.0.2272.76 Safari/537.36'];
        yield 'Chrome 37 AOL' => ['Mozilla/5.0 (Windows NT 6.2; WOW64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/37.0.2062.94 AOL/9.7 AOLBuild/4343.4049.US Safari/537.36'];
        yield 'Firefox 40' => ['Mozilla/5.0 (Macintosh; Intel Mac OS X 10.7; rv:40.0) Gecko/20100101 Firefox/40.0'];
        yield 'Internet Explorer 8' => ['Mozilla/4.0 (compatible; MSIE 8.0; Windows NT 6.1; Trident/4.0; SLCC2; .NET CLR 2.0.50727)'];
        yield 'Internet Explorer 11' => ['Mozilla/5.0 (Windows NT 6.1; WOW64; Trident/7.0; rv:11.0; MSN 9.0;MSN 9.1) like Gecko'];
        yield 'Safari 7' => ['Mozilla/5.0 (Macintosh; Intel Mac OS X 10_9_3) AppleWebKit/537.75.14 (KHTML, like Gecko) Version/7.0.3 Safari/7046A194A'];
    }

    #[DataProvider('botAgents')]
    public function testOutdatedBrowsersAreBots(string $agent): void
    {
        self::assertSame(BotCheck::OUTDATED_BROWSER, BotCheck::browserReason($agent));
    }

    /** @return iterable<string, array{string}> */
    public static function peopleAgents(): iterable
    {
        yield 'Firefox' => ['Mozilla/5.0 (X11; Linux x86_64; rv:158.0) Gecko/20100101 Firefox/158.0'];
        yield 'Chrome' => ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36'];
        yield 'Edge' => ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36 Edg/141.0.0.0'];
        yield 'Safari on an iPhone' => ['Mozilla/5.0 (iPhone; CPU iPhone OS 18_6 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.6 Mobile/15E148 Safari/604.1'];
        yield 'Chrome on an iPhone' => ['Mozilla/5.0 (iPhone; CPU iPhone OS 18_6 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) CriOS/141.0.7390.41 Mobile/15E148 Safari/604.1'];
        yield 'Chrome 81 on Android 4.4, the last it got' => ['Mozilla/5.0 (Linux; Android 4.4.2; SM-T230) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/81.0.4044.138 Safari/537.36'];
        yield 'a program' => ['curl/8.5.0'];
    }

    #[DataProvider('peopleAgents')]
    public function testCurrentBrowsersAndProgramsPass(string $agent): void
    {
        self::assertNull(BotCheck::browserReason($agent));
    }

    public function testNoBrowserNamed(): void
    {
        self::assertSame(BotCheck::NO_BROWSER, BotCheck::browserReason(null));
        self::assertSame(BotCheck::NO_BROWSER, BotCheck::browserReason('  '));
    }

    public function testTheTrapThenTheServersMarkThenTheBrowser(): void
    {
        $person = new Request(server: ['HTTP_USER_AGENT' => 'Mozilla/5.0 (X11; Linux x86_64; rv:158.0) Gecko/20100101 Firefox/158.0']);
        self::assertNull(BotCheck::reason($person, ''));
        self::assertSame(BotCheck::TRAP, BotCheck::reason($person, 'https://spam.example'));

        $marked = clone $person;
        $marked->headers->set(BotCheck::SUSPECT_HEADER, '1');
        self::assertSame(BotCheck::SUSPECT_NETWORK, BotCheck::reason($marked, ''));
        $marked->headers->set(BotCheck::SUSPECT_HEADER, '0');
        self::assertNull(BotCheck::reason($marked, ''), 'only 1 marks a network');

        $old = new Request(server: ['HTTP_USER_AGENT' => 'Mozilla/5.0 (Windows NT 6.1; WOW64; rv:38.0) Gecko/20100101 Firefox/38.0']);
        self::assertSame(BotCheck::OUTDATED_BROWSER, BotCheck::reason($old, ''));
        self::assertSame(BotCheck::TRAP, BotCheck::reason($old, 'x'), 'the trap is the surest sign, so it is the reason given');
    }
}
