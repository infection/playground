<?php

declare(strict_types=1);

use TwigStan\Error\BaselineError;

return [
    new BaselineError(
        'Variable \'example\' might not exist.',
        'offsetAccess.notFound',
        __DIR__ . '/templates/playground/index.html.twig',
        4,
    ),
    new BaselineError(
        'Offset \'message\' does not exist on mixed.',
        'getAttribute.offsetAccess.notFound',
        __DIR__ . '/templates/playground/_form.html.twig',
        2,
    ),
    new BaselineError(
        'Generator expects value type bool|float|int|string|Stringable|null, mixed given.',
        'generator.valueType',
        __DIR__ . '/templates/playground/_menu_versions.html.twig',
        3,
    ),
    new BaselineError(
        'Generator expects value type bool|float|int|string|Stringable|null, mixed given.',
        'generator.valueType',
        __DIR__ . '/templates/playground/_topbar.html.twig',
        8,
    ),
    new BaselineError(
        'Offset \'value\' does not exist on mixed.',
        'getAttribute.offsetAccess.notFound',
        __DIR__ . '/templates/playground/_topbar.html.twig',
        1,
    ),
    new BaselineError(
        'Offset \'label\' does not exist on mixed.',
        'getAttribute.offsetAccess.notFound',
        __DIR__ . '/templates/playground/_topbar.html.twig',
        1,
    ),
    new BaselineError(
        'Offset \'currentVersion\' does not exist on mixed.',
        'getAttribute.offsetAccess.notFound',
        __DIR__ . '/templates/playground/_topbar.html.twig',
        2,
    ),
    new BaselineError(
        'Offset \'data\' does not exist on mixed.',
        'getAttribute.offsetAccess.notFound',
        __DIR__ . '/templates/playground/_topbar.html.twig',
        2,
    ),
    new BaselineError(
        'The attribute "uri" may not be valid for all possible types of the union "Symfony\\Component\\HttpFoundation\\Request|null".',
        'getAttribute.maybeNotFound',
        __DIR__ . '/templates/playground/_topbar.html.twig',
        1,
    ),
    new BaselineError(
        'Variable \'example\' might not exist.',
        'offsetAccess.notFound',
        __DIR__ . '/templates/playground/_topbar.html.twig',
        1,
    ),
    new BaselineError(
        'Only numeric types are allowed in +, int|null given on the left side.',
        'plus.leftNonNumeric',
        __DIR__ . '/templates/explore/index.html.twig',
        1,
    ),
    new BaselineError(
        'Only numeric types are allowed in +, int|null given on the right side.',
        'plus.rightNonNumeric',
        __DIR__ . '/templates/explore/index.html.twig',
        1,
    ),
    new BaselineError(
        'Only numeric types are allowed in *, float|null given on the left side.',
        'mul.leftNonNumeric',
        __DIR__ . '/templates/explore/index.html.twig',
        1,
    ),
    new BaselineError(
        'Variable \'jsonLog\' might not exist.',
        'offsetAccess.notFound',
        __DIR__ . '/templates/playground/_results.html.twig',
        1,
    ),
    new BaselineError(
        'Variable \'example\' might not exist.',
        'offsetAccess.notFound',
        __DIR__ . '/templates/playground/_results.html.twig',
        1,
    ),
    new BaselineError(
        'Variable \'lineMap\' might not exist.',
        'offsetAccess.notFound',
        __DIR__ . '/templates/playground/_results.html.twig',
        1,
    ),
    new BaselineError(
        'Generator expects value type bool|float|int|string|Stringable|null, mixed given.',
        'generator.valueType',
        __DIR__ . '/templates/playground/_results.html.twig',
        1,
    ),
];
