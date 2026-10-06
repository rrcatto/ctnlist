<?php

declare(strict_types=1);

namespace App\Tests\Integration\Http;

use App\Http\SecurityHeadersListener;
use App\Tests\Integration\IntegrationTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class SecurityHeadersTest extends IntegrationTestCase
{
    public function testEveryResponseCarriesTheSecurityHeaders(): void
    {
        foreach (['/', '/login', '/no-such-page', '/settings'] as $path) {
            $response = $this->handle(Request::create($path));
            self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'), $path);
            self::assertSame('strict-origin-when-cross-origin', $response->headers->get('Referrer-Policy'), $path);
            self::assertSame(SecurityHeadersListener::CONTENT_SECURITY_POLICY, $response->headers->get('Content-Security-Policy'), $path);
            self::assertSame(SecurityHeadersListener::PERMISSIONS_POLICY, $response->headers->get('Permissions-Policy'), $path);
            self::assertFalse($response->headers->has('Strict-Transport-Security'), "{$path}: no HSTS over plain http");
        }
        self::assertSame('max-age=31536000', $this->handle(Request::create('https://ctnlist.test/'))->headers->get('Strict-Transport-Security'));
    }

    private function handle(Request $request): Response
    {
        $kernel = self::$kernel ?? throw new \LogicException('No kernel.');
        return $kernel->handle($request);
    }
}
