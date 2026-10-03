<?php

declare(strict_types=1);

use Dotenv\Dotenv;

$projectRoot = dirname(__DIR__);

require $projectRoot . '/vendor/autoload.php';

// ban.env is deployment configuration, not source. The location can be
// supplied to the Phinx command through GDB_ENV_DIRECTORY/GDB_ENV_FILE.
$banEnvDirectory = getenv('GDB_ENV_DIRECTORY');
$banEnvDirectory = is_string($banEnvDirectory) && trim($banEnvDirectory) !== ''
    ? rtrim(trim($banEnvDirectory), DIRECTORY_SEPARATOR)
    : __DIR__;
$banEnvName = getenv('GDB_ENV_FILE');
$banEnvName = is_string($banEnvName) && trim($banEnvName) !== ''
    ? basename(trim($banEnvName))
    : 'ban.env';
$banEnvPath = $banEnvDirectory . DIRECTORY_SEPARATOR . $banEnvName;
if (!is_file($banEnvPath)) {
    throw new RuntimeException('Global suppression database configuration file not found: ' . $banEnvPath);
}
Dotenv::createImmutable($banEnvDirectory, $banEnvName)->safeLoad();

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