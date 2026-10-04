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

// Compile the test container before any test runs. On a cold cache this
// autoloads Fat-Free's base.php, which constructs Base and installs its own
// error and exception handlers; remove them so tests start clean.
$errorHandler = get_error_handler();
$exceptionHandler = get_exception_handler();
$kernel = new App\Kernel('test', false);
$kernel->boot();
$kernel->shutdown();
while (get_error_handler() !== $errorHandler) {
    restore_error_handler();
}
while (get_exception_handler() !== $exceptionHandler) {
    restore_exception_handler();
}
