<?php

declare(strict_types=1);

namespace App;

use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

/**
 * One shared code tree serves several installations. The project directory is
 * the shared tree; each installation (the directory above its public_html,
 * holding .env) gets its own writable var/ for the container cache and logs.
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

    public function boot(): void
    {
        parent::boot();
        // Timestamps are written in PHP's timezone (the database runs in UTC),
        // so set it from the installation before any request is handled.
        date_default_timezone_set((string) $this->getContainer()->getParameter('app.timezone'));
    }

    /** @return array<string, mixed> */
    protected function getKernelParameters(): array
    {
        return parent::getKernelParameters() + ['kernel.instance_dir' => $this->instanceDir];
    }
}
