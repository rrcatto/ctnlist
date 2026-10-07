<?php

declare(strict_types=1);

namespace App\Twig;

use App\Config\SiteConfig;
use App\Http\ContentSecurityPolicy;
use App\Security\SubscriberUser;
use App\Subscriber\ProfileImages;
use Twig\Extension\AbstractExtension;
use Twig\Extension\GlobalsInterface;
use Twig\TwigFunction;

/**
 * Twig globals: `site` (SiteConfig) and `counters` (AdminCounters).
 * Function: `profile_image_url(app.user)`, the user's profile picture or the
 * placeholder (signed out, or no picture yet).
 */
final class AppExtension extends AbstractExtension implements GlobalsInterface
{
    public function __construct(
        private readonly SiteConfig $site,
        private readonly AdminCounters $counters,
        private readonly ProfileImages $profileImages,
        private readonly ContentSecurityPolicy $csp,
    ) {
    }

    /** @return array<string, mixed> */
    public function getGlobals(): array
    {
        return ['site' => $this->site, 'counters' => $this->counters];
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('profile_image_url', fn(mixed $user): string => $this->profileImages->url($user instanceof SubscriberUser ? $user->id : null)),
            // The page's Content-Security-Policy nonce, for the import map's inline scripts.
            new TwigFunction('csp_nonce', fn(): string => $this->csp->nonce()),
        ];
    }
}
