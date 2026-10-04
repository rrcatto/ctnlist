<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\EntryPoint\AuthenticationEntryPointInterface;
use Twig\Environment;

/**
 * Anonymous visitors who hit a protected page get the 403 error page, as in
 * v5, rather than a 401 or a login redirect.
 */
final class AccessDeniedEntryPoint implements AuthenticationEntryPointInterface
{
    public function __construct(private readonly Environment $twig)
    {
    }

    public function start(Request $request, ?AuthenticationException $authException = null): Response
    {
        return new Response(
            $this->twig->render('@Twig/Exception/error403.html.twig', ['status_code' => Response::HTTP_FORBIDDEN]),
            Response::HTTP_FORBIDDEN
        );
    }
}
