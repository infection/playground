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

use App\Entity\ExampleMutator;
use function is_array;
use function is_int;
use function is_numeric;
use function is_string;
use function json_decode;
use function mb_substr;

/**
 * The numbers of a run, read from Infection's JSON log (written with --log-verbosity=all).
 *
 * "Killed" is what Infection counts as detected without a timeout: killed by a test or by static
 * analysis, or errored. With escaped, timed out and not covered it adds up to the mutants the MSI counts.
 */
final class RunStats
{
    public const STATUS_KILLED = 'killed';
    public const STATUS_ESCAPED = 'escaped';
    public const STATUS_TIMED_OUT = 'timedOut';
    public const STATUS_NOT_COVERED = 'notCovered';

    /**
     * The lists of mutants in the log, by the status they count as.
     */
    private const LOG_LISTS = [
        'killed' => self::STATUS_KILLED,
        'killedByStaticAnalysis' => self::STATUS_KILLED,
        'errored' => self::STATUS_KILLED,
        'syntaxErrors' => self::STATUS_KILLED,
        'escaped' => self::STATUS_ESCAPED,
        'timeouted' => self::STATUS_TIMED_OUT,
        'uncovered' => self::STATUS_NOT_COVERED,
    ];

    /**
     * @param array<string, MutatorStats> $mutators mutator name => its mutants
     */
    public function __construct(
        public readonly int $total,
        public readonly int $killed,
        public readonly int $escaped,
        public readonly int $timedOut,
        public readonly int $notCovered,
        public readonly float $msi,
        public readonly float $coveredMsi,
        public readonly array $mutators,
    ) {
    }

    /**
     * @return self|null null when there is no log: Infection stopped before it generated mutants
     */
    public static function fromJsonLog(?string $jsonLog): ?self
    {
        if ($jsonLog === null) {
            return null;
        }

        $log = json_decode($jsonLog, true);

        if (!is_array($log) || !is_array($log['stats'] ?? null)) {
            return null;
        }

        $stats = $log['stats'];

        return new self(
            self::count($stats, 'totalMutantsCount'),
            self::count($stats, 'killedCount') + self::count($stats, 'killedByStaticAnalysisCount')
                + self::count($stats, 'errorCount') + self::count($stats, 'syntaxErrorCount'),
            self::count($stats, 'escapedCount'),
            self::count($stats, 'timeOutCount'),
            self::count($stats, 'notCoveredCount'),
            self::score($stats, 'msi'),
            self::score($stats, 'coveredCodeMsi'),
            self::countMutators($log),
        );
    }

    /**
     * @param array<mixed> $log
     *
     * @return array<string, MutatorStats>
     */
    private static function countMutators(array $log): array
    {
        $mutators = [];

        foreach (self::LOG_LISTS as $list => $status) {
            if (!is_array($log[$list] ?? null)) {
                continue;
            }

            foreach ($log[$list] as $mutant) {
                $name = is_array($mutant) && is_array($mutant['mutator'] ?? null) ? ($mutant['mutator']['mutatorName'] ?? null) : null;

                if (!is_string($name) || $name === '') {
                    continue;
                }

                $name = mb_substr($name, 0, ExampleMutator::MAX_MUTATOR_LENGTH);

                $mutators[$name] = ($mutators[$name] ?? new MutatorStats())->with($status);
            }
        }

        return $mutators;
    }

    /**
     * @param array<mixed> $stats
     */
    private static function count(array $stats, string $key): int
    {
        $value = $stats[$key] ?? 0;

        return is_int($value) ? $value : 0;
    }

    /**
     * @param array<mixed> $stats
     */
    private static function score(array $stats, string $key): float
    {
        $value = $stats[$key] ?? 0;

        return is_numeric($value) ? (float) $value : 0.0;
    }
}
