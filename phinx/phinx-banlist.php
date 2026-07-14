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

$driver = strtolower($env('GDB_DRIVER', 'pgsql'));
if ($driver !== 'pgsql') {
    throw new RuntimeException('phinx-banlist.php requires GDB_DRIVER=pgsql.');
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
            'host' => $env('GDB_HOST', '127.0.0.1'),
            'name' => $env('GDB_NAME', 'banlist'),
            'user' => $env('GDB_USER'),
            'pass' => $env('GDB_PASS'),
            'port' => (int) $env('GDB_PORT', '5432'),
            'charset' => 'utf8',
            'sslmode' => $env('GDB_SSLMODE', 'prefer'),
        ],
    ],
    'version_order' => 'creation',
];
