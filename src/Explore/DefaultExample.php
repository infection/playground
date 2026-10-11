<?php
/**
 * This code is licensed under the BSD 3-Clause License.
 *
 * Copyright (c) 2017, Maks Rafalko
 * All rights reserved.
 *
 * Redistribution and use in source and binary forms, with or without
 * modification, are permitted provided that the following conditions are met:
 *
 * * Redistributions of source code must retain the above copyright notice, this
 *   list of conditions and the following disclaimer.
 *
 * * Redistributions in binary form must reproduce the above copyright notice,
 *   this list of conditions and the following disclaimer in the documentation
 *   and/or other materials provided with the distribution.
 *
 * * Neither the name of the copyright holder nor the names of its
 *   contributors may be used to endorse or promote products derived from
 *   this software without specific prior written permission.
 *
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS"
 * AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE
 * IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE ARE
 * DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT HOLDER OR CONTRIBUTORS BE LIABLE
 * FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL
 * DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR
 * SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER
 * CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY,
 * OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE
 * OF THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
 */

declare(strict_types=1);

namespace App\Explore;

use function preg_replace;

/**
 * The code, test and config the playground opens with (DEFAULT_CODE, DEFAULT_TEST and DEFAULT_CONFIG
 * in assets/js/app/editor.js). Running them unchanged teaches nothing, so Explore leaves them out.
 */
final class DefaultExample
{
    public const CODE = <<<'PHP'
<?php

declare(strict_types=1);

namespace Infected;

class SourceClass
{
    public function add(int $a, int $b): int
    {
        return $a + $b;
    }
}
PHP;

    public const TEST = <<<'PHP'
<?php

declare(strict_types=1);

namespace Infected\Tests;

use Infected\SourceClass;
use PHPUnit\Framework\TestCase;

class SourceClassTest extends TestCase
{
    public function test_it_adds_2_numbers(): void
    {
        $source = new SourceClass();

        $result = $source->add(1, 2);

        self::assertSame(3, $result);
    }
}
PHP;

    public const CONFIG = <<<'JSON'
{
    "mutators": {
        "@default": true
    }
}
JSON;

    /**
     * Whitespace does not count: an added blank line still makes the default example.
     */
    public static function matches(string $code, string $test, string $config): bool
    {
        return self::normalize($code) === self::normalize(self::CODE)
            && self::normalize($test) === self::normalize(self::TEST)
            && self::normalize($config) === self::normalize(self::CONFIG);
    }

    private static function normalize(string $value): string
    {
        return (string) preg_replace('/\s+/', '', $value);
    }
}
