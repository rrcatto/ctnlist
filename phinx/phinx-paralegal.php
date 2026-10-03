<?php

declare(strict_types=1);

use Dotenv\Dotenv;

$projectRoot = dirname(__DIR__);
require $projectRoot . '/vendor/autoload.php';

$instanceEnvDirectory = (string) (
    $_ENV['INSTANCE_ENV_DIR']
    ?? $_SERVER['INSTANCE_ENV_DIR']
    ?? getenv('INSTANCE_ENV_DIR')
    ?: '/home/paralegal'
);

if (!is_dir($instanceEnvDirectory)) {
    throw new RuntimeException('Instance environment directory not found: ' . $instanceEnvDirectory);
}
Dotenv::createImmutable($instanceEnvDirectory)->safeLoad();

$env = static function (string $name, string $default = ''): string {
    $value = $_ENV[$name] ?? $_SERVER[$name] ?? getenv($name);
    return is_string($value) && $value !== '' ? $value : $default;
};

if (strtolower($env('DB_DRIVER', 'pgsql')) !== 'pgsql') {
    throw new RuntimeException('phinx-paralegal.php requires DB_DRIVER=pgsql.');
}

return [
    'paths' => [
        'migrations' => $projectRoot . '/database/migrations/domain',
        'seeds' => $projectRoot . '/database/seeds',
    ],
    'environments' => [
        'default_migration_table' => 'phinxlog',
        'default_environment' => 'production',
        'production' => [
            'adapter' => 'pgsql',
            'host' => $env('DB_HOST', '127.0.0.1'),
            'name' => $env('DB_NAME'),
            'user' => $env('DB_USER'),
            'pass' => $env('DB_PASS'),
            'port' => (int) $env('DB_PORT', '5432'),
            'charset' => 'utf8',
            'sslmode' => $env('DB_SSLMODE', 'prefer'),
        ],
    ],
    'version_order' => 'creation',
];