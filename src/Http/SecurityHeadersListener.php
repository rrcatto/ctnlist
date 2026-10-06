<?php

declare(strict_types=1);

namespace App\Http;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ResponseEvent;

/**
 * Security headers on every application response, independent of the web
 * server in front:
 *
 * - X-Content-Type-Options: nosniff;
 * - Referrer-Policy: strict-origin-when-cross-origin: subscriber links carry
 *   subscriber UUIDs in their paths, which must not reach external sites
 *   (e.g. links inside an archived message) through Referer;
 * - a Content-Security-Policy limited to directives that cannot break the
 *   pages (no framing by other sites, no <base> or plugin injection, forms
 *   post only to this site). A script/style policy needs nonces for the
 *   import map and is documented as a recommendation (README);
 * - Permissions-Policy denying powerful browser features ctnlist never uses;
 * - Strict-Transport-Security on HTTPS requests only (development over
 *   plain http is unaffected).
 *
 * X-Frame-Options is not sent: frame-ancestors supersedes it.
 */
#[AsEventListener]
final class SecurityHeadersListener
{
    public const CONTENT_SECURITY_POLICY = "frame-ancestors 'self'; base-uri 'self'; object-src 'none'; form-action 'self'";
    public const PERMISSIONS_POLICY = 'camera=(), microphone=(), geolocation=(), payment=(), usb=(), interest-cohort=()';

    public function __invoke(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        $headers = $event->getResponse()->headers;
        $headers->set('X-Content-Type-Options', 'nosniff');
        $headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        if (!$headers->has('Content-Security-Policy')) {
            $headers->set('Content-Security-Policy', self::CONTENT_SECURITY_POLICY);
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
