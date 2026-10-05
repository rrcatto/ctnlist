<?php

declare(strict_types=1);

namespace App\Twig;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\ErrorHandler\Exception\FlattenException;

/**
 * Decides when TwigErrorRenderer shows Symfony's exception page instead of
 * the site's error templates: in debug mode, and only for server errors.
 * Client errors (403, 404, …) always use the site layout, as in v5.
 */
final class ErrorPageDebug
{
    public function __construct(#[Autowire('%kernel.debug%')] private readonly bool $debug)
    {
    }

    public function __invoke(FlattenException $exception): bool
    {
        return $this->debug && $exception->getStatusCode() >= 500;
    }
}
