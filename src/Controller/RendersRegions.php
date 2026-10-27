<?php

declare(strict_types=1);

namespace App\Controller;

use App\Http\Htmx;
use App\Log\SiteLogListener;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * For controllers whose pages htmx updates in part (App\Http\Htmx): the
 * region a request asks for, and the answer that draws it.
 */
trait RendersRegions
{
    /**
     * The page, or the region of it that an htmx request asks for: $regions maps each region id the
     * page has to the template that draws it.
     *
     * @param array<string, string> $regions
     * @param array<string, mixed> $parameters
     */
    private function renderPage(Request $request, string $view, array $regions, array $parameters = [], ?Response $response = null): Response
    {
        $region = Htmx::region($request);
        if ($region === null || !isset($regions[$region])) {
            return $this->render($view, $parameters, $response);
        }
        if (Htmx::refreshesItself($request) && $this->getUser() !== null) {
            $this->quietRefresh($request);
        }
        return $this->renderRegion($regions[$region], $parameters, $response);
    }

    /**
     * After an action: back to the page the button was pressed on when htmx sent it (its region is then
     * drawn with the outcome), otherwise to $route as before.
     *
     * @param array<string, mixed> $parameters
     */
    private function redirectAfterAction(Request $request, string $route, array $parameters = []): RedirectResponse
    {
        return $this->redirect(Htmx::currentPage($request) ?? $this->generateUrl($route, $parameters));
    }

    /**
     * Draw one region: $view is the template that draws the element with the region's id. The flash
     * messages come with it (layout/region.html.twig), so an action that redirected here still shows its outcome.
     *
     * @param array<string, mixed> $parameters
     */
    private function renderRegion(string $view, array $parameters = [], ?Response $response = null): Response
    {
        return $this->render('layout/region.html.twig', ['region_view' => $view] + $parameters, $response);
    }

    /**
     * Mark a request that refreshes a region on a timer: the Site Log would otherwise record one
     * visit every few seconds. renderPage() does it for a signed-in user's region that draws itself
     * again (Htmx::refreshesItself()); a visitor's request is always recorded.
     */
    private function quietRefresh(Request $request): void
    {
        $request->attributes->set(SiteLogListener::NOT_LOGGED, true);
    }
}
