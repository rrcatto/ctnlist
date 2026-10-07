<?php

declare(strict_types=1);

namespace App\Http;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ResponseEvent;

/**
 * Security headers on every application response, independent of the web
 * server in front:
 *
 * - Content-Security-Policy (ContentSecurityPolicy: own origin only, nonce
 *   scripts, no framing; editor and archive profiles);
 * - X-Content-Type-Options: nosniff;
 * - Referrer-Policy: strict-origin-when-cross-origin: subscriber links carry
 *   subscriber UUIDs in their paths, which must not reach other sites;
 * - Permissions-Policy denying powerful browser features ctnlist never uses;
 * - Strict-Transport-Security on HTTPS requests only (development over
 *   plain http is unaffected).
 *
 * X-Frame-Options is not sent: frame-ancestors supersedes it. Static files
 * served directly by nginx get their headers from nginx (deploy/nginx).
 */
#[AsEventListener]
final class SecurityHeadersListener
{
    public const PERMISSIONS_POLICY = 'camera=(), microphone=(), geolocation=(), payment=(), usb=()';

    public function __construct(private readonly ContentSecurityPolicy $csp)
    {
    }

    public function __invoke(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        $headers = $event->getResponse()->headers;
        $headers->set('X-Content-Type-Options', 'nosniff');
        $headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        if (!$headers->has('Content-Security-Policy')) {
            $headers->set('Content-Security-Policy', $this->csp->header($event->getRequest()));
        }
        $headers->set('Permissions-Policy', self::PERMISSIONS_POLICY);
        if ($event->getRequest()->isSecure()) {
            $headers->set('Strict-Transport-Security', 'max-age=31536000');
        }
        if (!headers_sent()) {
            header_remove('X-Powered-By');
        }
    }
}
