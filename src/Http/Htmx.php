<?php

declare(strict_types=1);

namespace App\Http;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ResponseEvent;

/**
 * htmx requests (templates/CLAUDE.md, "htmx"). A page that htmx updates in
 * part draws each updatable part as a region: an element with an id, drawn
 * by its own template. htmx asks the page's own address for it with the
 * headers HX-Request, HX-Request-Type "partial" and HX-Target "tag#id", and
 * the controller then answers with that region alone (region()); every other
 * request, including htmx's history restore and a browser without
 * JavaScript, gets the whole page from the same address.
 *
 * As a listener it adds Vary for those headers to every response, so a
 * browser or proxy never answers a page load with a stored region.
 */
#[AsEventListener]
final class Htmx
{
    public const VARY = ['HX-Request', 'HX-Request-Type', 'HX-Target'];

    /** The id of the region an htmx request asks for, or null when the whole page is wanted. */
    public static function region(Request $request): ?string
    {
        $headers = $request->headers;
        if ($headers->get('HX-Request') !== 'true' || $headers->get('HX-Request-Type') !== 'partial'
            || $headers->get('HX-History-Restore-Request') === 'true') {
            return null;
        }
        $target = (string) $headers->get('HX-Target');
        $hash = strpos($target, '#');
        $id = $hash === false ? '' : rawurldecode(substr($target, $hash + 1));
        return preg_match('/^[A-Za-z][\w-]*$/', $id) === 1 ? $id : null;
    }

    /** Whether the request comes from a region that draws itself again on a timer (HX-Source is HX-Target). */
    public static function refreshesItself(Request $request): bool
    {
        $source = $request->headers->get('HX-Source');
        return self::region($request) !== null && $source !== null && $source === $request->headers->get('HX-Target');
    }

    /**
     * For an htmx action: the page the browser is showing (HX-Current-URL), as a path with its query,
     * when it is on this site; null otherwise. An action redirects there so that htmx draws the region
     * of the very page, filters and page number included, that the button was pressed on.
     */
    public static function currentPage(Request $request): ?string
    {
        $url = $request->headers->get('HX-Request') === 'true' ? (string) $request->headers->get('HX-Current-URL') : '';
        $parts = parse_url($url);
        if ($parts === false || !isset($parts['scheme'], $parts['host'], $parts['path'])
            || strcasecmp($parts['host'], $request->getHost()) !== 0 || !str_starts_with($parts['path'], '/') || str_starts_with($parts['path'], '//')) {
            return null;
        }
        return $parts['path'] . (isset($parts['query']) ? '?' . $parts['query'] : '');
    }

    public function __invoke(ResponseEvent $event): void
    {
        if ($event->isMainRequest()) {
            $event->getResponse()->setVary(implode(', ', self::VARY), false);
        }
    }
}
