<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Tests\Smoke\RouteSmokeTest;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Conventions every route must follow, checked over the whole route table
 * so a new route cannot silently skip them:
 *
 * - every route that accepts POST is protected against CSRF: a Symfony form
 *   (its own token) or #[IsCsrfTokenValid], unless listed in CSRF_EXEMPT
 *   with the reason;
 * - every administration route declares the ACL permission it needs
 *   (#[IsGranted] on the method or class), unless listed in OWNER_OR_ADMIN;
 * - every GET route is requested by the route smoke test, unless listed in
 *   SMOKE_EXEMPT with the reason.
 */
final class RouteConventionsTest extends IntegrationTestCase
{
    /** POST routes protected by something other than the session CSRF token. */
    private const CSRF_EXEMPT = [
        'cattomail_webhook' => 'authenticated by catto-mail\'s HMAC signature (WebhookSignature)',
        'consent_unsubscribe_link' => 'RFC 8058 one-click unsubscribe from mail clients: authenticated by the link\'s HMAC signature',
        'app_logout' => 'firewall logout with enable_csrf (field csrf, token id ctnlist)',
    ];

    /** Administration routes that also serve the subscriber themselves; the controller checks owner or subscribers.manage. */
    private const OWNER_OR_ADMIN = ['admin_subscriber', 'admin_subscriber_save'];

    /** GET routes the route smoke test does not request, and why. */
    private const SMOKE_EXEMPT = [
        'auth_verify' => 'consumes a one-time sign-in token: AuthFlowTest',
        'consent_unsubscribe_link' => 'needs a valid HMAC signature: OneClickUnsubscribeTest (forged links: CattoMailSmokeTest)',
    ];

    public function testStateChangingRoutesAreCsrfProtected(): void
    {
        foreach ($this->routes() as $name => [$methods, $class, $method]) {
            if (!in_array('POST', $methods, true) || isset(self::CSRF_EXEMPT[$name])) {
                continue;
            }
            self::assertNotNull($class, "{$name} has a controller");
            $reflection = new \ReflectionMethod($class, (string) $method);
            $usesForm = (bool) preg_match('/->(createForm|createNamed)\(|->handleRequest\(/', $this->source($reflection) . $this->helpers($reflection));
            self::assertTrue($usesForm || $reflection->getAttributes(IsCsrfTokenValid::class) !== [],
                "{$name} accepts POST without a Symfony form or #[IsCsrfTokenValid]");
        }
    }

    public function testAdministrationRoutesDeclareTheirPermission(): void
    {
        foreach ($this->routes() as $name => [, $class, $method]) {
            if ($class === null || !str_starts_with($class, 'App\\Controller\\Admin\\') || in_array($name, self::OWNER_OR_ADMIN, true)) {
                continue;
            }
            $granted = (new \ReflectionMethod($class, (string) $method))->getAttributes(IsGranted::class) !== []
                || (new \ReflectionClass($class))->getAttributes(IsGranted::class) !== [];
            self::assertTrue($granted, "{$name} has no #[IsGranted] permission");
        }
    }

    public function testNoGetRouteChangesStateByName(): void
    {
        // Mutating actions are named for what they do; none of them may be reachable by GET.
        foreach ($this->routes() as $name => [$methods]) {
            if (preg_match('/_(submit|save|delete|clear|reset|create|update|assign|unassign|permissions|requeue|refresh|delivery_ok|withdraw)$/', $name) === 1) {
                self::assertNotContains('GET', $methods, "{$name} changes state and must not accept GET");
            }
        }
    }

    public function testEveryGetRouteHasSmokeCoverage(): void
    {
        $smoke = [];
        foreach (['PUBLIC', 'LOGIN_REQUIRED', 'ADMIN'] as $constant) {
            /** @var list<string> $paths */
            $paths = (new \ReflectionClassConstant(RouteSmokeTest::class, $constant))->getValue();
            array_push($smoke, ...array_map(static fn(string $p): string => (string) preg_replace('/\{\w+\}/', 'X', $p), $paths));
        }
        foreach ($this->routes() as $name => [$methods, , , $path]) {
            if (!in_array('GET', $methods, true) || isset(self::SMOKE_EXEMPT[$name])) {
                continue;
            }
            $pattern = '#^' . preg_replace('/\\\\\{\w+\\\\\}/', '[^/]+', preg_quote($path, '#')) . '$#';
            $covered = array_filter($smoke, static fn(string $p): bool => preg_match($pattern, $p) === 1);
            self::assertNotEmpty($covered, "GET route {$name} ({$path}) is not requested by RouteSmokeTest");
        }
    }

    /** @return array<string, array{0: list<string>, 1: ?class-string, 2: ?string, 3: string}> */
    private function routes(): array
    {
        $routes = [];
        foreach ($this->service(RouterInterface::class)->getRouteCollection() as $name => $route) {
            $controller = $route->getDefault('_controller');
            [$class, $method] = is_string($controller) && str_contains($controller, '::') ? explode('::', $controller, 2) : [null, null];
            /** @var ?class-string $class */
            $routes[$name] = [array_values($route->getMethods() ?: ['GET', 'POST']), $class, $method, $route->getPath()];
        }
        return $routes;
    }

    private function source(\ReflectionMethod $method): string
    {
        $lines = file((string) $method->getFileName()) ?: [];
        return implode('', array_slice($lines, (int) $method->getStartLine() - 1, (int) $method->getEndLine() - (int) $method->getStartLine() + 1));
    }

    /** The private helpers the action calls (forms are often built in one, e.g. contactForm()). */
    private function helpers(\ReflectionMethod $method): string
    {
        $source = '';
        preg_match_all('/\$this->(\w+)\(/', $this->source($method), $calls);
        foreach (array_unique($calls[1]) as $call) {
            $class = $method->getDeclaringClass();
            if ($class->hasMethod($call) && $class->getMethod($call)->getDeclaringClass()->getName() === $class->getName()) {
                $source .= $this->source($class->getMethod($call));
            }
        }
        return $source;
    }
}
