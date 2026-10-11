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

/**
 * The tabs of Explore: each one filters and orders the listed runs.
 */
enum Ranking: string
{
    case Latest = 'latest';
    case PerfectScore = 'perfect';
    case MostMutants = 'mutants';
    case MostSurvivors = 'survivors';
    case CustomConfig = 'config';
    case LatestVersion = 'version';

    /**
     * A tiny class with a single killed mutant has 100% MSI and teaches nothing.
     */
    public const MIN_MUTANTS = 5;

    public function label(): string
    {
        return match ($this) {
            self::Latest => 'Latest',
            self::PerfectScore => 'Perfect score',
            self::MostMutants => 'Most mutants',
            self::MostSurvivors => 'Most survivors',
            self::CustomConfig => 'Custom config',
            self::LatestVersion => 'Latest Infection',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Latest => 'The newest runs: what people are trying right now.',
            self::PerfectScore => 'Tests that kill every one of at least 5 mutants.',
            self::MostMutants => 'The code that generates the most mutants, and how its tests hold up.',
            self::MostSurvivors => 'The most escaped mutants: weak assertions and missed boundaries to fix.',
            self::CustomConfig => 'Runs that turn mutators on or off, or change their settings.',
            self::LatestVersion => 'Runs on the newest Infection release the playground has.',
        };
    }
}
