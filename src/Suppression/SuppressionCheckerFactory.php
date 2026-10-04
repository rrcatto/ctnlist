<?php

declare(strict_types=1);

namespace App\Suppression;

use App\Config\SiteConfig;
use Doctrine\DBAL\DriverManager;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Dotenv\Dotenv;

/**
 * Selects the suppression source from SUPPRESSION_PROVIDER. The banlist
 * connection settings come from the external ban.env (GDB_ENV_DIRECTORY /
 * GDB_ENV_FILE, default config/phinx/ban.env), read without touching the
 * process environment.
 */
final class SuppressionCheckerFactory
{
    public function __construct(
        #[Autowire(env: 'SUPPRESSION_PROVIDER')] private readonly string $provider,
        #[Autowire(env: 'GDB_ENV_DIRECTORY')] private readonly string $banEnvDirectory,
        #[Autowire(env: 'GDB_ENV_FILE')] private readonly string $banEnvFile,
        #[Autowire('%kernel.project_dir%')] private readonly string $projectDir,
        #[Autowire('%kernel.environment%')] private readonly string $environment,
        private readonly SiteConfig $site,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function create(): SuppressionChecker
    {
        return match (strtolower($this->provider)) {
            'none' => $this->none(),
            'banlist' => new BanlistSuppressionChecker($this->banlistConnection(), $this->site, $this->logger),
            default => throw new \RuntimeException('Unknown SUPPRESSION_PROVIDER: ' . $this->provider),
        };
    }

    private function none(): NullSuppressionChecker
    {
        if (!in_array($this->environment, ['dev', 'test'], true)) {
            throw new \RuntimeException('SUPPRESSION_PROVIDER=none is only permitted when APP_ENV is dev or test.');
        }
        return new NullSuppressionChecker($this->logger);
    }

    private function banlistConnection(): \Doctrine\DBAL\Connection
    {
        $directory = rtrim($this->banEnvDirectory !== '' ? $this->banEnvDirectory : $this->projectDir . '/config/phinx', '/');
        $file = $directory . '/' . basename($this->banEnvFile !== '' ? $this->banEnvFile : 'ban.env');
        if (!is_file($file)) {
            throw new \RuntimeException('Global suppression database configuration file not found: ' . $file);
        }
        $env = (new Dotenv())->parse((string) file_get_contents($file), $file);
        if (strtolower($env['GDB_DRIVER'] ?? 'pgsql') !== 'pgsql') {
            throw new \RuntimeException('The global suppression database must use PostgreSQL.');
        }
        return DriverManager::getConnection([
            'driver' => 'pdo_pgsql',
            'host' => $env['GDB_HOST'] ?? '127.0.0.1',
            'port' => (int) ($env['GDB_PORT'] ?? 5432),
            'dbname' => $env['GDB_NAME'] ?? 'banlist',
            'user' => $env['GDB_USER'] ?? '',
            'password' => $env['GDB_PASS'] ?? '',
            'sslmode' => $env['GDB_SSLMODE'] ?? 'prefer',
        ]);
    }
}
