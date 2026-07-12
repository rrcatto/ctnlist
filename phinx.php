<?php

declare(strict_types=1);

use Dotenv\Dotenv;

require __DIR__ . '/vendor/autoload.php';

Dotenv::createImmutable(__DIR__)->safeLoad();

$env = static function (string $name, string $default = ''): string {
    $value = $_ENV[$name] ?? $_SERVER[$name] ?? getenv($name);
    return is_string($value) && $value !== '' ? $value : $default;
};

$database = static function (string $suffix = '') use ($env): array {
    $driver = strtolower($env('DB_DRIVER' . $suffix, $env('DB_DRIVER', 'pgsql')));
    if (!in_array($driver, ['pgsql', 'mysql'], true)) {
        throw new RuntimeException('Unsupported DB_DRIVER: ' . $driver);
    }

    $defaultPort = $driver === 'pgsql' ? 5432 : 3306;
    $config = [
        'adapter' => $driver,
        'host' => $env('DB_HOST' . $suffix, '127.0.0.1'),
        'name' => $env('DB_NAME' . $suffix, 'ctnlist'),
        'user' => $env('DB_USER' . $suffix, 'ctnlist'),
        'pass' => $env('DB_PASS' . $suffix, ''),
        'port' => (int) $env('DB_PORT' . $suffix, (string) $defaultPort),
        'charset' => $driver === 'mysql' ? 'utf8mb4' : 'utf8',
    ];

    if ($driver === 'pgsql') {
        $config['sslmode'] = $env('DB_SSLMODE' . $suffix, $env('DB_SSLMODE', 'prefer'));
    }

    return $config;
};

return [
    'paths' => [
        'migrations' => __DIR__ . '/database/migrations',
        'seeds' => __DIR__ . '/database/seeds',
    ],
    'environments' => [
        'default_migration_table' => 'phinxlog',
        'default_environment' => 'development',
        'development' => $database('_DEV'),
        'production' => $database(),
        'testing' => $database('_TEST'),
    ],
    'version_order' => 'creation',
];
