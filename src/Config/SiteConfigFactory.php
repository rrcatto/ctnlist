<?php

declare(strict_types=1);

namespace App\Config;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/** Builds SiteConfig at runtime from the environment, with the administrator's setting overrides applied. */
final class SiteConfigFactory
{
    public function __construct(
        private readonly RuntimeSettings $settings,
        #[Autowire('%kernel.instance_dir%')] private readonly string $instanceDir,
    ) {
    }

    public function __invoke(): SiteConfig
    {
        return SiteConfig::fromEnvironment($this->settings->environment(), $this->instanceDir);
    }
}
