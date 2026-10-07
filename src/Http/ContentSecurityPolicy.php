<?php

declare(strict_types=1);

namespace App\Http;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * The Content-Security-Policy of every page (SecurityHeadersListener).
 *
 * Everything the application loads comes from its own origin: application
 * CSS/JS through AssetMapper, Bootstrap and Bootstrap Icons vendored under
 * assets/lib, CKEditor under public_html/vendor. The only inline scripts are
 * the import map and its entry point, which carry a per-request nonce
 * ({{ csp_nonce() }}). There is no 'unsafe-eval' anywhere, no inline style
 * on ordinary pages, and no page may be framed.
 *
 * Two kinds of page show HTML an administrator authored, and get a profile
 * through their route's `_csp` default:
 * - EDITOR (message and template editors): CKEditor writes inline styles,
 *   and authored content may show remote (https) images;
 * - ARCHIVE (the public archive page): the sanitised campaign HTML keeps its
 *   inline styles (email layout) and may show remote https images. Script
 *   stays nonce-only, so authored HTML still cannot run code.
 *
 * Authored HTML may also link plain http images; upgrade-insecure-requests
 * (sent over HTTPS) fetches them over https.
 */
final class ContentSecurityPolicy
{
    public const EDITOR = 'editor';
    public const ARCHIVE = 'archive';

    private const NONCE = '_csp_nonce';

    public function __construct(private readonly RequestStack $requests)
    {
    }

    /** The current page's nonce, created when a template first asks for it. */
    public function nonce(): string
    {
        $request = $this->requests->getMainRequest();
        if ($request === null) {
            return '';
        }
        $nonce = $request->attributes->get(self::NONCE);
        if (!is_string($nonce)) {
            $nonce = rtrim(strtr(base64_encode(random_bytes(18)), '+/', '-_'), '=');
            $request->attributes->set(self::NONCE, $nonce);
        }
        return $nonce;
    }

    public function header(Request $request): string
    {
        $nonce = $request->attributes->get(self::NONCE);
        $profile = $request->attributes->get('_csp');
        $authored = in_array($profile, [self::EDITOR, self::ARCHIVE], true);
        $directives = [
            'default-src' => "'self'",
            'script-src' => "'self'" . (is_string($nonce) ? " 'nonce-{$nonce}'" : ''),
            'style-src' => "'self'" . ($authored ? " 'unsafe-inline'" : ''),
            // blob: and data: images can only come from this site's own scripts (the profile picture editor).
            'img-src' => "'self' data: blob:" . ($authored ? ' https:' : ''),
            'font-src' => "'self'",
            'connect-src' => "'self'",
            'object-src' => "'none'",
            'base-uri' => "'none'",
            'form-action' => "'self'",
            'frame-ancestors' => "'none'",
        ];
        $policy = implode('; ', array_map(static fn(string $name, string $value): string => $name . ' ' . $value, array_keys($directives), $directives));
        return $request->isSecure() ? $policy . '; upgrade-insecure-requests' : $policy;
    }
}
