<?php

declare(strict_types=1);

namespace App\Legacy;

use Base;
use Psr\Container\ContainerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireLocator;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;

/**
 * Catch-all controller that serves the routes not yet ported to Symfony by
 * running them through Fat-Free (temporary migration bridge).
 *
 * The legacy objects are built by the container. Their output is buffered, and
 * the headers they send with header()/setcookie() are moved onto the Symfony
 * response, so Symfony's session and response listeners still apply.
 */
#[AsController]
final class LegacyBridge
{
    /**
     * Resolution order matters: UsersController authenticates from the
     * request cookie in its constructor, and SiteLogController records the
     * request (including the user) in its constructor.
     */
    private const SERVICES = [
        'options', 'user', 'sendlog', 'smlog', 'subscriber', 'message', 'template', 'archive',
        'mailer', 'queue', 'listService', 'listsController', 'rolesController', 'sitelog',
    ];

    public function __construct(
        #[AutowireLocator([
            'fat' => Base::class,
            'options' => OptionsController::class,
            'user' => UsersController::class,
            'sendlog' => SendlogController::class,
            'smlog' => SmlogController::class,
            'subscriber' => SubscribersController::class,
            'message' => MessagesController::class,
            'template' => TemplatesController::class,
            'archive' => ArchivesController::class,
            'mailer' => mailer::class,
            'queue' => QueueController::class,
            'listService' => ListService::class,
            'listsController' => ListsController::class,
            'rolesController' => RolesController::class,
            'sitelog' => SiteLogController::class,
        ])]
        private readonly ContainerInterface $legacy,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        // Legacy code reads and writes $_SESSION directly. Starting Symfony's
        // session first means F3 finds it active and both share one handler.
        $session = $request->getSession();
        $session->start();

        $renderError = null;
        ob_start();
        try {
            $fat = $this->legacy->get('fat');
            $services = array_map($this->legacy->get(...), self::SERVICES);
            $routes = require __DIR__ . '/routes.php';
            $renderError = $routes($fat, ...$services);
            $fat->run();
            $response = new Response((string) ob_get_clean(), http_response_code() ?: Response::HTTP_OK);
        } catch (LegacyRedirect $redirect) {
            ob_end_clean();
            $response = new RedirectResponse($redirect->url, $redirect->permanent ? 301 : 302);
        } catch (LegacyHttpError $error) {
            ob_clean();
            if ($renderError !== null) {
                $renderError($error->statusCode);
            }
            $response = new Response((string) ob_get_clean(), $error->statusCode);
        } catch (\Throwable $e) {
            ob_end_clean();
            throw $e;
        }

        $this->moveNativeHeaders($response, $session->getName());
        return $response;
    }

    /** Transfer headers sent with header()/setcookie() to the response. */
    private function moveNativeHeaders(Response $response, string $sessionName): void
    {
        $seen = [];
        foreach (headers_list() as $line) {
            [$name, $value] = array_map('trim', explode(':', $line, 2) + [1 => '']);
            $key = strtolower($name);
            if ($key === 'x-powered-by') {
                continue;
            }
            if ($key === 'set-cookie') {
                $cookie = Cookie::fromString($value);
                // Symfony's session listener sets the session cookie itself.
                if ($cookie->getName() !== $sessionName) {
                    $response->headers->setCookie($cookie);
                }
                continue;
            }
            // The first value replaces Symfony's default (e.g. Content-Type,
            // Cache-Control); repeated headers are appended.
            $response->headers->set($name, $value, !isset($seen[$key]));
            $seen[$key] = true;
        }
        header_remove();
    }
}
