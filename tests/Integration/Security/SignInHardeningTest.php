<?php

declare(strict_types=1);

namespace App\Tests\Integration\Security;

use App\Repository\SettingRepository;
use App\Security\AuthCookie;
use App\Security\MagicLinkRequester;
use App\Tests\Integration\IntegrationTestCase;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\TerminableInterface;

/** Sign-in wording that follows the configured lifetime, and the auth cookie's attributes. */
final class SignInHardeningTest extends IntegrationTestCase
{
    public function testTheDefaultLifetimeIsStated(): void
    {
        self::assertStringContainsString('valid for 30 minutes', $this->get('/login'));
    }

    public function testTheLoginPageAndEmailStateTheConfiguredLifetime(): void
    {
        // A Settings override, set before anything reads the settings in this request cycle.
        $this->service(SettingRepository::class)->set('AUTH_MAGIC_LINK_TTL', '7200', false, null);
        self::assertSame(7200, $this->service(MagicLinkRequester::class)->lifetimeSeconds(), 'the value authentication uses');
        self::assertStringContainsString('valid for 2 hours', $this->get('/login'));
        self::assertStringNotContainsString('30 minutes', $this->get('/login'));

        $this->createSubscriber('jane@example.com');
        $this->service(MagicLinkRequester::class)->request('jane@example.com');
        $email = $this->getMailerMessage();
        self::assertInstanceOf(\Symfony\Component\Mime\Email::class, $email);
        self::assertStringContainsString('expires in 2 hours', (string) $email->getTextBody());
        self::assertSame(7200, (int) $this->db->fetchOne(
            "SELECT EXTRACT(EPOCH FROM alt_expires_at - alt_created_at) FROM auth_login_tokens WHERE alt_email = 'jane@example.com'"
        ), 'and the token really lives that long');
    }

    public function testAuthCookieAttributes(): void
    {
        $cookie = $this->service(AuthCookie::class);
        // Test installation: APP_BASE_URL is https, so even a request seen as plain http gets a Secure cookie.
        $issued = $cookie->create(AuthCookie::newToken(), Request::create('http://ctnlist.test/auth/verify'));
        self::assertTrue($issued->isSecure(), 'Secure follows the https site, not a proxy\'s view of the request');
        self::assertTrue($issued->isHttpOnly());
        self::assertSame(Cookie::SAMESITE_LAX, $issued->getSameSite());
        self::assertSame('/', $issued->getPath());
        self::assertNull($issued->getDomain(), 'host-only');
        self::assertEqualsWithDelta(time() + $cookie->ttl, $issued->getExpiresTime(), 5, 'lives as long as the auth session');
    }

    private function get(string $path): string
    {
        $kernel = self::$kernel ?? throw new \LogicException('No kernel.');
        $request = Request::create($path);
        $response = $kernel->handle($request);
        if ($kernel instanceof TerminableInterface) {
            $kernel->terminate($request, $response);
        }
        return (string) $response->getContent();
    }
}
