<?php

/**
 * Returns the importmap for this application.
 *
 * - "path" is a path inside the asset mapper system. Use the
 *     "debug:asset-map" command to see the full list of paths.
 *
 * - "entrypoint" (JavaScript only) set to true for any module that will
 *     be used as an "entrypoint" (and passed to the importmap() Twig function).
 *
 * The "importmap:require" command can be used to add new entries to this file.
 *
 * Monaco Editor is not listed here: see assets/js/monaco.js.
 */
return [
    'app' => [
        'path' => './assets/js/app.js',
        'entrypoint' => true,
    ],
    'ast' => [
        'path' => './assets/js/ast.js',
        'entrypoint' => true,
    ],
    'explore' => [
        'path' => './assets/js/explore.js',
        'entrypoint' => true,
    ],
];
