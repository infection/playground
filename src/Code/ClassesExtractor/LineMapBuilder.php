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

namespace App\Code\ClassesExtractor;

use function basename;
use function explode;
use function is_array;
use function is_int;
use function is_string;
use function json_decode;
use function preg_replace;
use function str_starts_with;
use function substr;
use Throwable;
use function trim;

/**
 * Infection reports the lines of the pretty-printed class files, the editor shows the submitted code.
 *
 * The class files of a run are not stored: they are printed again, and the printer changed with php-parser 5.
 * The files are printed the ways the runs could have been made, and the one Infection's log matches is used.
 */
final class LineMapBuilder
{
    /**
     * @var list<ClassesExtractor> the current printing first: it is kept when the log matches several
     */
    private array $extractors;

    public function __construct()
    {
        $this->extractors = [
            ClassesExtractorFactory::create(),
            ClassesExtractorFactory::create(new PhpParser4Printer()),
        ];
    }

    /**
     * @return array<string, array<int, int>> class name => [line in the class file => line in the submitted code]
     */
    public function build(string $code, ?string $jsonLog): array
    {
        $mutants = self::collectMutants($jsonLog);

        $bestLineMap = [];
        $bestMatchCount = -1;

        foreach ($this->extractors as $extractor) {
            try {
                $extractedClasses = $extractor->extract($code);
            } catch (Throwable) {
                return [];
            }

            $lineMap = [];
            $matchCount = 0;

            foreach ($extractedClasses as $extractedClass) {
                $lineMap[$extractedClass->getClassName()] = $extractedClass->getLineMap();
                $matchCount += self::countMatchingMutants($extractedClass, $mutants[$extractedClass->getClassName()] ?? []);
            }

            if ($matchCount > $bestMatchCount) {
                $bestLineMap = $lineMap;
                $bestMatchCount = $matchCount;
            }
        }

        return $bestLineMap;
    }

    /**
     * @param list<array{line: int, diffLine: string}> $mutants
     */
    private static function countMatchingMutants(ExtractedClass $extractedClass, array $mutants): int
    {
        $lines = explode("\n", $extractedClass->getCode());
        $matchCount = 0;

        foreach ($mutants as $mutant) {
            $line = $lines[$mutant['line'] - 1] ?? null;

            if ($line !== null && self::normalize($line) === self::normalize($mutant['diffLine'])) {
                ++$matchCount;
            }
        }

        return $matchCount;
    }

    /**
     * Infection prints the diff with php-parser 5, so an `if` in an `else` is shown as `} else if`.
     */
    private static function normalize(string $line): string
    {
        return (string) preg_replace('/^}\s*else\s+/', '', trim($line));
    }

    /**
     * @return array<string, list<array{line: int, diffLine: string}>> class name => the line of each mutant and its first removed line
     */
    private static function collectMutants(?string $jsonLog): array
    {
        $log = $jsonLog === null ? null : json_decode($jsonLog, true);

        if (!is_array($log)) {
            return [];
        }

        $mutants = [];

        foreach ($log as $list) {
            if (!is_array($list)) {
                continue;
            }

            foreach ($list as $mutant) {
                $mutator = is_array($mutant) ? ($mutant['mutator'] ?? null) : null;

                if (!is_array($mutator) || !is_string($mutator['originalFilePath'] ?? null) || !is_int($mutator['originalStartLine'] ?? null) || !is_string($mutant['diff'] ?? null)) {
                    continue;
                }

                $diffLine = self::findFirstRemovedLine($mutant['diff']);

                if ($diffLine === null) {
                    continue;
                }

                $mutants[basename($mutator['originalFilePath'], '.php')][] = [
                    'line' => $mutator['originalStartLine'],
                    'diffLine' => $diffLine,
                ];
            }
        }

        return $mutants;
    }

    private static function findFirstRemovedLine(string $diff): ?string
    {
        foreach (explode("\n", $diff) as $line) {
            if (str_starts_with($line, '-') && !str_starts_with($line, '---')) {
                return substr($line, 1);
            }
        }

        return null;
    }
}
