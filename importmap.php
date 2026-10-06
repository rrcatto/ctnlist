<?php

/**
 * Import map for the application's own JavaScript (Symfony AssetMapper).
 *
 * The entry point is assets/app.js; it also pulls in assets/styles/app.css.
 * There are no remote packages: Bootstrap and Bootstrap Icons are loaded from
 * their CDN in templates/base.html.twig.
 */
return [
    'app' => [
        'path' => './assets/app.js',
        'entrypoint' => true,
    ],
];
