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

namespace App\Tests\Unit\Explore;

use App\Explore\DefaultExample;
use function file_get_contents;
use function implode;
use PHPUnit\Framework\TestCase;
use function preg_match;
use function preg_match_all;
use function str_replace;

final class DefaultExampleTest extends TestCase
{
    public function test_it_matches_the_editors_defaults(): void
    {
        self::assertTrue(DefaultExample::matches(
            self::editorDefault('DEFAULT_CODE'),
            self::editorDefault('DEFAULT_TEST'),
            self::editorDefault('DEFAULT_CONFIG'),
        ));
    }

    public function test_it_ignores_whitespace(): void
    {
        self::assertTrue(DefaultExample::matches(
            "\n" . str_replace('    ', "\t", self::editorDefault('DEFAULT_CODE')) . "\n\n",
            str_replace("\n", "\r\n", self::editorDefault('DEFAULT_TEST')),
            '{"mutators": {"@default": true}}',
        ));
    }

    public function test_it_does_not_match_a_change_of_the_code_the_test_or_the_config(): void
    {
        $code = self::editorDefault('DEFAULT_CODE');
        $test = self::editorDefault('DEFAULT_TEST');
        $config = self::editorDefault('DEFAULT_CONFIG');

        self::assertFalse(DefaultExample::matches(str_replace('$a + $b', '$a - $b', $code), $test, $config));
        self::assertFalse(DefaultExample::matches($code, str_replace('(1, 2)', '(2, 2)', $test), $config));
        self::assertFalse(DefaultExample::matches($code, $test, '{"mutators": {"@default": false}}'));
    }

    /**
     * The defaults are lists of lines joined in assets/js/app/editor.js.
     */
    private static function editorDefault(string $constant): string
    {
        $editor = (string) file_get_contents(__DIR__ . '/../../../assets/js/app/editor.js');

        self::assertSame(1, preg_match('/const ' . $constant . ' = \[\n(.*?)\n\]\.join/s', $editor, $matches));
        self::assertGreaterThan(0, preg_match_all("/^\\s*'(.*)',?$/m", $matches[1], $lines));

        return str_replace(['\\\\'], ['\\'], implode("\n", $lines[1]));
    }
}
