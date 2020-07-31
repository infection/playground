<?php

declare(strict_types=1);

namespace App\Code;

use function preg_match;

final class ClassNameFromCodeExtractor
{
    private const DEFAULT_CLASS_NAME = 'SourceClass';

    public function extract(string $code): string
    {
        $matches = [];

        if (preg_match('/class\s+(.*?)\s*{/', $code, $matches) === 1) {
            return $matches[1];
        }

        return self::DEFAULT_CLASS_NAME;
    }
}
