<?php

declare(strict_types=1);

namespace App\Config;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/** Builds SiteConfig at runtime from the environment loaded by the Symfony runtime. */
final class SiteConfigFactory
{
    public function __construct(
        #[Autowire('%kernel.instance_dir%')] private readonly string $instanceDir,
    ) {
    }

    public function __invoke(): SiteConfig
    {
        return SiteConfig::fromEnvironment($_ENV + $_SERVER, $this->instanceDir);
    }
}
