<?php

declare(strict_types=1);

namespace App\Twig;

use App\CattoMail\SendingSetup;
use App\Config\SiteConfig;
use App\Http\ContentSecurityPolicy;
use App\Security\SubscriberUser;
use App\Subscriber\ProfileImages;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Twig\Extension\AbstractExtension;
use Twig\Extension\GlobalsInterface;
use Twig\TwigFunction;

/**
 * Twig globals: `site` (SiteConfig), `counters` (AdminCounters) and
 * `app_version` (SiteConfig::VERSION, in every page's generator meta tag and footer).
 * Functions: `profile_image_url(app.user)`, the user's profile picture or the
 * placeholder (signed out, or no picture yet); `sending_setup()`, where Set up sending
 * stands (App\CattoMail\SendingSetup::state(), for the notice on administration pages).
 */
final class AppExtension extends AbstractExtension implements GlobalsInterface
{
    public function __construct(
        private readonly SiteConfig $site,
        private readonly AdminCounters $counters,
        private readonly ProfileImages $profileImages,
        private readonly ContentSecurityPolicy $csp,
        // Built only when a page asks (an administrator's): it reads the mail service configuration.
        #[Autowire(lazy: true)] private readonly SendingSetup $sendingSetup,
    ) {
    }

    /** @return array<string, mixed> */
    public function getGlobals(): array
    {
        return ['site' => $this->site, 'counters' => $this->counters, 'app_version' => SiteConfig::VERSION];
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('profile_image_url', fn(mixed $user): string => $this->profileImages->url($user instanceof SubscriberUser ? $user->id : null)),
            // The page's Content-Security-Policy nonce, for the import map's inline scripts.
            new TwigFunction('csp_nonce', fn(): string => $this->csp->nonce()),
            new TwigFunction('sending_setup', fn(): array => $this->sendingSetup->state()),
        ];
    }
}
