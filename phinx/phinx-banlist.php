<?php

declare(strict_types=1);

use Dotenv\Dotenv;

$projectRoot = dirname(__DIR__);

require $projectRoot . '/vendor/autoload.php';

Dotenv::createImmutable(__DIR__, 'ban.env')->safeLoad();

$env = static function (string $name, string $default = ''): string {
    $value = $_ENV[$name] ?? $_SERVER[$name] ?? getenv($name);

    return is_string($value) && $value !== '' ? $value : $default;
};

$driver = strtolower($env('DB_DRIVER', 'pgsql'));
if ($driver !== 'pgsql') {
    throw new RuntimeException('phinx-banlist.php requires DB_DRIVER=pgsql.');
}

return [
    'paths' => [
        'migrations' => $projectRoot . '/database/migrations/banlist',
        'seeds' => $projectRoot . '/database/seeds',
    ],
    'environments' => [
        'default_migration_table' => 'phinxlog',
        'default_environment' => 'production',
        'production' => [
            'adapter' => 'pgsql',
            'host' => $env('DB_HOST', '127.0.0.1'),
            'name' => $env('DB_NAME', 'banlist'),
            'user' => $env('DB_USER'),
            'pass' => $env('DB_PASS'),
            'port' => (int) $env('DB_PORT', '5432'),
            'charset' => 'utf8',
            'sslmode' => $env('DB_SSLMODE', 'prefer'),
        ],
    ],
    'version_order' => 'creation',
];
