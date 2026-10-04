<?php

declare(strict_types=1);

namespace App\Twig;

use App\Config\SiteConfig;
use Twig\Extension\AbstractExtension;
use Twig\Extension\GlobalsInterface;

/** Twig globals: `site` (SiteConfig) and `counters` (AdminCounters). */
final class AppExtension extends AbstractExtension implements GlobalsInterface
{
    public function __construct(
        private readonly SiteConfig $site,
        private readonly AdminCounters $counters,
    ) {
    }

    /** @return array<string, mixed> */
    public function getGlobals(): array
    {
        return ['site' => $this->site, 'counters' => $this->counters];
    }
}
