<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Liveness for a reverse proxy or uptime monitor: "OK" while PHP-FPM and the
 * application answer. It deliberately reveals nothing else (no database,
 * version, configuration or worker state: those are on the Delivery page and
 * in `ctnlist:diagnose`), queries no database and is not written to the Site
 * Log, so frequent polling leaves no trace.
 */
final class HealthController
{
    #[Route('/health', name: 'health', methods: ['GET'])]
    public function __invoke(): Response
    {
        return new Response("OK\n", Response::HTTP_OK, ['Content-Type' => 'text/plain; charset=UTF-8', 'Cache-Control' => 'no-store', 'X-Robots-Tag' => 'noindex']);
    }
}
