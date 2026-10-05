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

// Compile a fresh test container before any test runs (APP_DEBUG=0 would
// otherwise keep a stale one after code changes).
$kernel = new App\Kernel('test', false);
(new Symfony\Component\Filesystem\Filesystem())->remove($kernel->getCacheDir());
$kernel->boot();
$kernel->shutdown();
