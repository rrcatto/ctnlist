<?php

declare(strict_types=1);

use Dotenv\Dotenv;

require __DIR__ . '/vendor/autoload.php';

Dotenv::createImmutable(__DIR__)->safeLoad();

$database = static function (string $suffix = ''): array {
    return [
        'adapter' => 'pgsql',
        'host' => $_ENV['DB_HOST' . $suffix] ?? $_SERVER['DB_HOST' . $suffix] ?? '127.0.0.1',
        'name' => $_ENV['DB_NAME' . $suffix] ?? $_SERVER['DB_NAME' . $suffix] ?? 'ctnlist',
        'user' => $_ENV['DB_USER' . $suffix] ?? $_SERVER['DB_USER' . $suffix] ?? 'ctnlist',
        'pass' => $_ENV['DB_PASS' . $suffix] ?? $_SERVER['DB_PASS' . $suffix] ?? '',
        'port' => (int) ($_ENV['DB_PORT' . $suffix] ?? $_SERVER['DB_PORT' . $suffix] ?? 5432),
        'charset' => 'utf8',
    ];
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
