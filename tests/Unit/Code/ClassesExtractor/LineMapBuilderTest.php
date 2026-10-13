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

namespace App\Tests\Unit\Code\ClassesExtractor;

use App\Code\ClassesExtractor\LineMapBuilder;
use function count;
use function json_encode;
use const JSON_THROW_ON_ERROR;
use PHPUnit\Framework\TestCase;

final class LineMapBuilderTest extends TestCase
{
    private const CODE = <<<'PHP'
        <?php

        namespace Infected;

        final class SourceClass
        {
            public function get(int $a): int
            {
                if ($a > 10) {
                    return 1;
                } else {
                    if ($a < 5) {
                        return 2;
                    }
                }

                return $a + 3;
            }
        }
        PHP;

    public function test_it_maps_the_lines_of_a_run_made_with_the_submitted_class(): void
    {
        // the class is copied below a header two lines longer than the submitted one
        $lineMap = (new LineMapBuilder())->build(self::CODE, self::buildJsonLog([
            [14, '            if ($a < 5) {'],
            [19, '        return $a + 3;'],
        ]));

        self::assertSame(12, $lineMap['SourceClass'][14]);
        self::assertSame(17, $lineMap['SourceClass'][19]);
    }

    public function test_it_maps_the_lines_of_a_run_made_with_php_parser_5(): void
    {
        // php-parser 5 prints `} else if ($a < 5) {` on line 13 and `return $a + 3;` on line 16
        $lineMap = (new LineMapBuilder())->build(self::CODE, self::buildJsonLog([
            [13, '        } else if ($a < 5) {'],
            [16, '        return $a + 3;'],
        ]));

        self::assertSame(12, $lineMap['SourceClass'][13]);
        self::assertSame(17, $lineMap['SourceClass'][16]);
    }

    public function test_it_maps_the_lines_of_a_run_made_with_php_parser_4(): void
    {
        // php-parser 4 printed `} else {` and `if ($a < 5) {` on lines of their own: `return $a + 3;` is on line 18
        $lineMap = (new LineMapBuilder())->build(self::CODE, self::buildJsonLog([
            // a mutant without a removed line is skipped, not the ones after it
            [14, null],
            [14, '        } else if ($a < 5) {'],
            [18, '        return $a + 3;'],
        ]));

        self::assertSame(12, $lineMap['SourceClass'][14]);
        self::assertSame(17, $lineMap['SourceClass'][18]);
    }

    public function test_it_maps_the_lines_of_the_submitted_class_without_a_log(): void
    {
        $lineMap = (new LineMapBuilder())->build(self::CODE, null);

        self::assertSame(17, $lineMap['SourceClass'][19]);
    }

    public function test_it_returns_no_lines_for_code_that_does_not_parse(): void
    {
        self::assertSame([], (new LineMapBuilder())->build('<?php class {', null));
    }

    /**
     * @param list<array{int, string|null}> $mutants the line Infection reports and the line the diff removes
     */
    private static function buildJsonLog(array $mutants): string
    {
        $escaped = [];

        foreach ($mutants as [$line, $removedLine]) {
            $escaped[] = [
                'mutator' => [
                    'mutatorName' => 'LessThan',
                    'originalFilePath' => '/app/infection-builds/abcd/src/SourceClass.php',
                    'originalStartLine' => $line,
                ],
                'diff' => $removedLine === null
                    ? "--- Original\n+++ New\n@@ @@\n+// mutated"
                    : "--- Original\n+++ New\n@@ @@\n-{$removedLine}\n+{$removedLine} // mutated",
            ];
        }

        // the lists of mutants are read past the entries that are not lists
        return json_encode(['version' => 1, 'stats' => ['totalMutantsCount' => count($mutants)], 'escaped' => $escaped], JSON_THROW_ON_ERROR);
    }
}
