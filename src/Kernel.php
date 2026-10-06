<?php

declare(strict_types=1);

namespace App;

use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

/**
 * One shared code tree serves several installations. The project directory is
 * the shared tree; each installation (the directory above its public_html,
 * holding .env) gets its own writable var/ for the container cache and logs,
 * and its own public_html/ (web root, including compiled assets).
 */
final class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    private readonly string $instanceDir;

    public function __construct(string $environment, bool $debug, ?string $instanceDir = null)
    {
        parent::__construct($environment, $debug);
        $this->instanceDir = rtrim(
            $instanceDir ?? (string) ($_SERVER['CTNLIST_INSTANCE_DIR'] ?? $this->getProjectDir()),
            '/'
        );
    }

    public function getProjectDir(): string
    {
        return \dirname(__DIR__);
    }

    public function getInstanceDir(): string
    {
        return $this->instanceDir;
    }

    public function getCacheDir(): string
    {
        return $this->instanceDir . '/var/cache/' . $this->environment;
    }

    public function getLogDir(): string
    {
        return $this->instanceDir . '/var/log';
    }

    protected function build(ContainerBuilder $container): void
    {
        // AssetMapper writes compiled assets below the public directory, which
        // FrameworkBundle takes from the shared tree's composer.json. Each
        // installation has its own web root, so point it there instead.
        $container->addCompilerPass(new class implements CompilerPassInterface {
            public function process(ContainerBuilder $container): void
            {
                if (!$container->hasDefinition('asset_mapper.local_public_assets_filesystem')) {
                    return;
                }
                $instanceDir = $container->getParameter('kernel.instance_dir');
                $prefix = $container->getDefinition('asset_mapper.public_assets_path_resolver')->getArgument(0);
                if (!is_string($instanceDir) || !is_string($prefix)) {
                    throw new \LogicException('Unexpected AssetMapper configuration.');
                }
                $publicDir = $instanceDir . '/public_html';
                $parameters = $container->getParameterBag();
                $container->getDefinition('asset_mapper.local_public_assets_filesystem')
                    ->setArgument(0, $parameters->escapeValue($publicDir));
                $container->getDefinition('asset_mapper.compiled_asset_mapper_config_reader')
                    ->setArgument(0, $parameters->escapeValue(rtrim($publicDir . '/' . ltrim($prefix, '/'), '/')));
            }
        });

        if ($this->environment === 'test') {
            // Integration tests fetch services that nothing uses yet; keep them
            // (and make them public) instead of letting the container prune them.
            $container->addCompilerPass(new class implements CompilerPassInterface {
                public function process(ContainerBuilder $container): void
                {
                    foreach ($container->getDefinitions() as $id => $definition) {
                        if (str_starts_with($id, 'App\\') && !$definition->isAbstract()) {
                            $definition->setPublic(true);
                        }
                    }
                }
            }, PassConfig::TYPE_BEFORE_REMOVING);
        }
    }

    public function boot(): void
    {
        parent::boot();
        // Timestamps are written in PHP's timezone (the database runs in UTC),
        // so set it from the installation before any request is handled.
        $timezone = $this->getContainer()->getParameter('app.timezone');
        date_default_timezone_set(is_string($timezone) && $timezone !== '' ? $timezone : 'UTC');
    }

    /** @return array<string, mixed> */
    protected function getKernelParameters(): array
    {
        return parent::getKernelParameters() + ['kernel.instance_dir' => $this->instanceDir];
    }
}
