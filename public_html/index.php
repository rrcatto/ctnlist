<?php

declare(strict_types=1);

/**
 * ctnlist per-installation front controller.
 *
 * Copy public_html/ into each installation. The installation directory (the
 * parent of public_html) holds .env and the writable var/ and logs/
 * directories; the code, configuration and Composer dependencies live in the
 * shared tree below.
 */

use App\Kernel;

$sharedDirectory = '/usr/local/lib/php/ctnlist/6.0.4/';
$instanceDirectory = dirname(__DIR__);

// The runtime loads <installation>/.env (plus any .env.local / .env.<env>).
$_SERVER['APP_RUNTIME_OPTIONS'] = ['project_dir' => $instanceDirectory];

require_once $sharedDirectory . 'vendor/autoload_runtime.php';

return static fn(array $context): Kernel => new Kernel(
    (string) $context['APP_ENV'],
    (bool) $context['APP_DEBUG'],
    $instanceDirectory
);
