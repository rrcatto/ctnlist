<?php

declare(strict_types=1);

namespace App\Tests\Smoke;

/** The Twig site layout and the error pages. */
final class LayoutTest extends SmokeTestCase
{
    public function testAnonymousLayout(): void
    {
        $body = self::request(self::client(), 'GET', '/')['body'];
        self::assertMatchesRegularExpression('/<title>.+ \| Home<\/title>/', $body);
        self::assertStringContainsString('href="/login"', $body);
        self::assertStringNotContainsString('navbarDropdownAdminMenu', $body);
    }

    public function testAdministratorLayout(): void
    {
        $client = self::client();
        self::loginAsAdmin($client);
        $body = self::request($client, 'GET', '/lists')['body'];

        self::assertMatchesRegularExpression('/<title>.+ \| Lists<\/title>/', $body);
        self::assertStringContainsString('navbarDropdownAdminMenu', $body);
        self::assertMatchesRegularExpression('/Subscribers - \d+/', $body);
        self::assertStringContainsString('href="/logout"', $body);
        self::assertStringContainsString('Create a topic list', $body, 'page content inside the layout');
    }

    public function testStandaloneStorePage(): void
    {
        $body = self::request(self::client(), 'GET', '/store')['body'];
        self::assertStringNotContainsString('navbarDropdownAdminMenu', $body);
        self::assertMatchesRegularExpression("/xAffiliate\\('[^']+'\\)/", $body);
    }

    public function testErrorPages(): void
    {
        $forbidden = self::request(self::client(), 'GET', '/lists');
        self::assertSame(403, $forbidden['status']);
        self::assertStringContainsString('Access denied.', $forbidden['body']);
        self::assertStringContainsString('href="/login"', $forbidden['body'], 'in the site layout');

        $missing = self::request(self::client(), 'GET', '/no-such-page');
        self::assertSame(404, $missing['status']);
        self::assertStringContainsString('<h1>Not Found</h1>', $missing['body']);
    }
}
