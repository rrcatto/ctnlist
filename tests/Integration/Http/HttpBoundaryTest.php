<?php

declare(strict_types=1);

namespace App\Tests\Integration\Http;

use App\Tests\Integration\IntegrationTestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\HttpKernel\TerminableInterface;
use Symfony\Component\Routing\Exception\MethodNotAllowedException;
use Symfony\Component\Routing\Matcher\UrlMatcher;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\RouterInterface;

/**
 * The HTTP boundary as a client meets it: method restrictions over the whole
 * route table, request-size limits, production error pages and the health
 * check. (Route-table conventions themselves: RouteConventionsTest.)
 */
final class HttpBoundaryTest extends IntegrationTestCase
{
    /**
     * Every route answers a request with the wrong method with 405 and an
     * Allow header, before any controller, firewall or CSRF check runs: a
     * link or an image can never trigger a POST action.
     */
    public function testEveryRouteRefusesTheWrongMethod(): void
    {
        $router = $this->service(RouterInterface::class);
        $checked = 0;
        foreach ($router->getRouteCollection() as $name => $route) {
            $methods = $route->getMethods();
            if ($methods === [] || str_starts_with($name, '_')) {
                continue;
            }
            $path = self::examplePath($route->getPath(), $route->getRequirements());
            $wrong = in_array('GET', $methods, true) ? 'PUT' : 'GET';
            // Another route may legitimately answer the same path with this method (e.g. a GET form page for a POST action).
            try {
                (new UrlMatcher($router->getRouteCollection(), new RequestContext(method: $wrong)))->match($path);
                continue;
            } catch (MethodNotAllowedException) {
            } catch (\Throwable) {
                continue;
            }
            $response = $this->handle(Request::create($path, $wrong));
            self::assertSame(405, $response->getStatusCode(), "{$wrong} {$path} ({$name})");
            foreach ($methods as $method) {
                self::assertStringContainsString($method, (string) $response->headers->get('Allow'), "{$name}: Allow header");
            }
            $checked++;
        }
        self::assertGreaterThan(50, $checked, 'the route table was walked');
    }

    /** A webhook that declares an oversized body is refused before the body is read, signature checked or anything stored. */
    public function testTheWebhookRefusesADeclaredOversizedBodyUnread(): void
    {
        $request = Request::create('/cattomail/webhook', 'POST', server: ['CONTENT_TYPE' => 'application/json', 'CONTENT_LENGTH' => '5000000'], content: '{}');
        $response = $this->handle($request);
        self::assertSame(413, $response->getStatusCode());
        self::assertSame('{"status":"payload-too-large"}', $response->getContent());

        $actual = Request::create('/cattomail/webhook', 'POST', server: ['CONTENT_TYPE' => 'application/json'], content: str_repeat(' ', 1048577));
        self::assertSame(413, $this->handle($actual)->getStatusCode(), 'an undeclared (chunked) body is measured too');
        self::assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM cattomail_webhook_events'));
    }

    /**
     * With debug off (production), an unexpected fault shows the site's error
     * page: no exception class, message, SQL, file path or stack trace.
     *
     * Symfony chooses the error renderer by runtime mode: PHP-FPM is "web"
     * (the HTML/Twig renderer), a CLI process such as PHPUnit gets a text
     * dump meant for a terminal. The test runs in web mode, as a site does.
     */
    public function testProductionErrorPagesRevealNothing(): void
    {
        self::assertFalse((bool) self::getContainer()->getParameter('kernel.debug'), 'the test kernel runs like production');
        $_SERVER['APP_RUNTIME_MODE'] = 'web=1';
        try {
            $this->assertErrorPagesRevealNothing();
        } finally {
            unset($_SERVER['APP_RUNTIME_MODE']);
        }
    }

    private function assertErrorPagesRevealNothing(): void
    {
        $secret = 'SQLSTATE[42P01]: relation "subscribers" does not exist; DB_PASS=hunter2 in /usr/local/lib/php/ctnlist/src/Repository/X.php:12';
        $fault = static function (RequestEvent $event) use ($secret): void {
            if ($event->isMainRequest() && $event->getRequest()->getPathInfo() === '/privacy') {
                throw new \RuntimeException($secret);
            }
        };
        $dispatcher = $this->service(EventDispatcherInterface::class);
        $dispatcher->addListener(KernelEvents::REQUEST, $fault, 1000);
        try {
            $response = $this->handle(Request::create('/privacy'));
        } finally {
            $dispatcher->removeListener(KernelEvents::REQUEST, $fault);
        }
        $body = (string) $response->getContent();
        self::assertSame(500, $response->getStatusCode());
        foreach (['SQLSTATE', 'hunter2', '/usr/local', 'RuntimeException', 'Stack trace', '.php'] as $leak) {
            self::assertStringNotContainsString($leak, $body, $leak);
        }
        self::assertStringContainsString('<html', $body, 'the site error page');

        foreach (['/no-such-page' => 404, '/lists' => 403] as $path => $status) {
            $page = $this->handle(Request::create($path));
            self::assertSame($status, $page->getStatusCode(), $path);
            self::assertStringNotContainsString('Exception', (string) $page->getContent(), $path);
        }
    }

    /**
     * An anonymous page without a form starts no PHP session (no sessions-table
     * read, no cookie): the layout reads flash messages only from an existing
     * session, so pages, including the error page, still render while the
     * database is unavailable.
     */
    public function testAnonymousPagesStartNoSession(): void
    {
        foreach (['/', '/privacy', '/no-such-page'] as $path) {
            $request = Request::create($path);
            $response = $this->handle($request);
            self::assertFalse($request->hasSession() && $request->getSession()->isStarted(), $path);
            self::assertSame([], $response->headers->getCookies(), $path);
        }
    }

    /** The health check says "OK" and nothing else, starts no session and is not a Site Log visit. */
    public function testTheHealthCheckRevealsNothing(): void
    {
        $before = (int) $this->db->fetchOne('SELECT COUNT(*) FROM sitelog');
        $request = Request::create('/health');
        $response = $this->handle($request);
        $kernel = self::$kernel;
        if ($kernel instanceof TerminableInterface) {
            $kernel->terminate($request, $response);
        }
        self::assertSame(200, $response->getStatusCode());
        self::assertSame("OK\n", $response->getContent());
        self::assertStringStartsWith('text/plain', (string) $response->headers->get('Content-Type'));
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        self::assertSame([], $response->headers->getCookies(), 'no session or auth cookie');
        self::assertSame($before, (int) $this->db->fetchOne('SELECT COUNT(*) FROM sitelog'), 'not recorded as a visit');
    }

    /** @param array<string, string> $requirements */
    private static function examplePath(string $path, array $requirements): string
    {
        return (string) preg_replace_callback('/\{(\w+)\}/', static function (array $m) use ($requirements): string {
            $requirement = $requirements[$m[1]] ?? '';
            return match (true) {
                str_contains($requirement, '\d') => '1',
                str_contains($requirement, '{8}') || str_contains($requirement, '{36}') => '01a1163b-10f8-7974-9bf5-f6ab4347b618',
                str_contains($requirement, '0-9a-f') => 'abc123',
                str_starts_with($requirement, '[A-Z]') => 'X',
                preg_match('/^[a-z|]+$/', $requirement) === 1 => explode('|', $requirement)[0],
                default => 'x',
            };
        }, $path);
    }

    private function handle(Request $request): Response
    {
        $kernel = self::$kernel ?? throw new \LogicException('No kernel.');
        return $kernel->handle($request);
    }
}
