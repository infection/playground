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

use App\Explore\MutatorStats;
use App\Explore\RunStats;
use function array_map;
use Generator;
use function json_encode;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use function str_repeat;

final class RunStatsTest extends TestCase
{
    public function test_it_reads_the_numbers_and_the_mutators_of_a_log(): void
    {
        $stats = RunStats::fromJsonLog((string) json_encode([
            'stats' => [
                'totalMutantsCount' => 12,
                'killedCount' => 3,
                'killedByStaticAnalysisCount' => 1,
                'errorCount' => 1,
                'syntaxErrorCount' => 1,
                'escapedCount' => 3,
                'timeOutCount' => 2,
                'notCoveredCount' => 1,
                'msi' => 66.67,
                'coveredCodeMsi' => 72.73,
            ],
            'killed' => [self::mutant('Plus'), self::mutant('Plus'), self::mutant('Minus')],
            'killedByStaticAnalysis' => [self::mutant('Minus')],
            'errored' => [self::mutant('Minus')],
            'syntaxErrors' => [self::mutant('Minus')],
            'escaped' => [self::mutant('Plus'), self::mutant('LogicalAnd'), self::mutant('LogicalAnd')],
            'timeouted' => [self::mutant('Plus'), self::mutant('Minus')],
            'uncovered' => [self::mutant('LogicalAnd')],
            'ignored' => [self::mutant('Ignored')],
        ]));

        self::assertNotNull($stats);
        self::assertSame(12, $stats->total);
        self::assertSame(6, $stats->killed);
        self::assertSame(3, $stats->escaped);
        self::assertSame(2, $stats->timedOut);
        self::assertSame(1, $stats->notCovered);
        self::assertSame(66.67, $stats->msi);
        self::assertSame(72.73, $stats->coveredMsi);
        self::assertSame(
            [
                'Plus' => [2, 1, 1, 0],
                'Minus' => [4, 0, 1, 0],
                'LogicalAnd' => [0, 2, 0, 1],
            ],
            self::countsOf($stats->mutators),
        );
    }

    public function test_it_reads_integer_scores_and_defaults_missing_numbers_to_zero(): void
    {
        $stats = RunStats::fromJsonLog('{"stats": {"totalMutantsCount": 2, "killedCount": 2, "msi": 100}}');

        self::assertNotNull($stats);
        self::assertSame(2, $stats->total);
        self::assertSame(2, $stats->killed);
        self::assertSame(0, $stats->escaped);
        self::assertSame(0, $stats->timedOut);
        self::assertSame(0, $stats->notCovered);
        self::assertSame(100.0, $stats->msi);
        self::assertSame(0.0, $stats->coveredMsi);
        self::assertSame([], $stats->mutators);
    }

    public function test_it_ignores_malformed_numbers_and_mutants(): void
    {
        $stats = RunStats::fromJsonLog((string) json_encode([
            'stats' => ['totalMutantsCount' => '3', 'msi' => 'n/a', 'coveredCodeMsi' => '50.5'],
            'killed' => 'none',
            'escaped' => [
                'not a mutant',
                ['mutator' => 'Plus'],
                ['mutator' => ['mutatorName' => 42]],
                ['mutator' => ['mutatorName' => '']],
                self::mutant(str_repeat('a', 70)),
            ],
        ]));

        self::assertNotNull($stats);
        self::assertSame(0, $stats->total);
        self::assertSame(0.0, $stats->msi);
        self::assertSame(50.5, $stats->coveredMsi);
        self::assertSame([str_repeat('a', 64) => [0, 1, 0, 0]], self::countsOf($stats->mutators));
    }

    #[DataProvider('provideLogsWithoutStats')]
    public function test_it_has_no_stats_without_a_log(?string $jsonLog): void
    {
        self::assertNull(RunStats::fromJsonLog($jsonLog));
    }

    public static function provideLogsWithoutStats(): Generator
    {
        yield 'no log' => [null];

        yield 'invalid JSON' => ['{'];

        yield 'not an object' => ['42'];

        yield 'no stats' => ['{"escaped": []}'];

        yield 'stats of the wrong type' => ['{"stats": 1}'];
    }

    /**
     * @param array<string, MutatorStats> $mutators
     *
     * @return array<string, list<int>> mutator name => killed, escaped, timed out and not covered mutants
     */
    private static function countsOf(array $mutators): array
    {
        return array_map(
            static fn (MutatorStats $stats): array => [$stats->killed, $stats->escaped, $stats->timedOut, $stats->notCovered],
            $mutators,
        );
    }

    /**
     * @return array{mutator: array{mutatorName: string}}
     */
    private static function mutant(string $mutatorName): array
    {
        return ['mutator' => ['mutatorName' => $mutatorName]];
    }
}
