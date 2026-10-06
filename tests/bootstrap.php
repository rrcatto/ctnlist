<?php

declare(strict_types=1);

use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__) . '/vendor/autoload.php';

// The installation's .env supplies anything phpunit.dist.xml does not set
// (variables already present are not overridden).
$instanceDir = $_SERVER['CTNLIST_INSTANCE_DIR'] ?? getenv('CTNLIST_INSTANCE_DIR');
if (is_string($instanceDir) && is_file($instanceDir . '/.env')) {
    (new Dotenv())->load($instanceDir . '/.env');
}

// Secret settings are encrypted with APP_SETTINGS_KEY; use a throwaway key
// when the installation has none.
if (trim((string) ($_SERVER['APP_SETTINGS_KEY'] ?? $_ENV['APP_SETTINGS_KEY'] ?? '')) === '') {
    $_SERVER['APP_SETTINGS_KEY'] = $_ENV['APP_SETTINGS_KEY'] = base64_encode(random_bytes(32));
    putenv('APP_SETTINGS_KEY=' . $_SERVER['APP_SETTINGS_KEY']);
}

// Compile a fresh test container before any test runs (APP_DEBUG=0 would
// otherwise keep a stale one after code changes).
$kernel = new App\Kernel('test', false);
(new Symfony\Component\Filesystem\Filesystem())->remove($kernel->getCacheDir());
$kernel->boot();
$kernel->shutdown();
