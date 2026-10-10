<?php

/**
 * Import map for the application's own JavaScript (Symfony AssetMapper).
 *
 * The entry point is assets/app.js. There are no remote packages: stylesheets
 * (assets/styles/app.css and the vendored Bootstrap and Bootstrap Icons under
 * assets/lib) are linked from templates/base.html.twig with asset(). Bootstrap's
 * JavaScript bundle is an ES module that app.js imports by its relative path,
 * so AssetMapper adds it to the import map by itself. The import map's inline
 * scripts carry the Content-Security-Policy nonce.
 */
return [
    'app' => [
        'path' => './assets/app.js',
        'entrypoint' => true,
    ],
];
