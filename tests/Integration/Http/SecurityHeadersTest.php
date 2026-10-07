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
            self::assertSame(SecurityHeadersListener::PERMISSIONS_POLICY, $response->headers->get('Permissions-Policy'), $path);
            self::assertFalse($response->headers->has('Strict-Transport-Security'), "{$path}: no HSTS over plain http");
            self::assertNotNull($response->headers->get('Content-Security-Policy'), $path . ' (error pages too)');
        }
        $secure = $this->handle(Request::create('https://ctnlist.test/'));
        self::assertSame('max-age=31536000', $secure->headers->get('Strict-Transport-Security'));
        self::assertStringEndsWith('; upgrade-insecure-requests', (string) $secure->headers->get('Content-Security-Policy'));
    }

    /** Ordinary pages: own origin only, scripts by nonce, no inline style, no eval, no framing, no external origin. */
    public function testOrdinaryPagesGetTheStrictPolicy(): void
    {
        foreach (['/', '/no-such-page'] as $path) {
            $response = $this->handle(Request::create($path));
            $policy = (string) $response->headers->get('Content-Security-Policy');
            if ($path === '/') {
                self::assertMatchesRegularExpression("/script-src 'self' 'nonce-([A-Za-z0-9_-]{24})';/", $policy, $path);
                preg_match("/'nonce-([A-Za-z0-9_-]+)'/", $policy, $nonce);
                self::assertStringContainsString('<script type="importmap" nonce="' . ($nonce[1] ?? '?') . '"', (string) $response->getContent(), 'the inline import map carries the nonce');
            } else {
                self::assertStringContainsString("script-src 'self';", $policy, 'the minimal 404 page has no inline script, so no nonce');
            }
            foreach (["style-src 'self';", "img-src 'self' data: blob:;", "frame-ancestors 'none'", "object-src 'none'", "base-uri 'none'", "form-action 'self'", "default-src 'self'"] as $directive) {
                self::assertStringContainsString($directive, $policy, $path);
            }
            foreach (['unsafe-eval', 'unsafe-inline', 'http', '*'] as $absent) {
                self::assertStringNotContainsString($absent, $policy, $path);
            }
            self::assertStringNotContainsString('cdn.jsdelivr.net', (string) $response->getContent(), 'no third-party frontend files');
        }
        $other = (string) $this->handle(Request::create('/'))->headers->get('Content-Security-Policy');
        self::assertNotSame($other, (string) $this->handle(Request::create('/'))->headers->get('Content-Security-Policy'), 'a new nonce for every page');
    }

    /** The archive page shows authored campaign HTML: inline styles and https images, but still no script. */
    public function testTheArchiveProfileRelaxesOnlyStylesAndImages(): void
    {
        $id = (int) $this->db->fetchOne("INSERT INTO archives (a_subject, a_html) VALUES ('A', '<p style=\"color:red\">x</p>') RETURNING a_id");
        $policy = (string) $this->handle(Request::create('/archive/' . $id))->headers->get('Content-Security-Policy');
        self::assertStringContainsString("style-src 'self' 'unsafe-inline';", $policy);
        self::assertStringContainsString("img-src 'self' data: blob: https:;", $policy);
        self::assertMatchesRegularExpression("/script-src 'self' 'nonce-[A-Za-z0-9_-]+';/", $policy, 'scripts stay nonce-only');
        self::assertStringNotContainsString('unsafe-eval', $policy);
    }

    private function handle(Request $request): Response
    {
        $kernel = self::$kernel ?? throw new \LogicException('No kernel.');
        return $kernel->handle($request);
    }
}
