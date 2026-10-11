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

use App\Explore\ConfigChanges;
use Generator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ConfigChangesTest extends TestCase
{
    /**
     * @param list<string> $expected
     */
    #[DataProvider('provideConfigs')]
    public function test_it_lists_what_a_config_changes_in_the_mutators(string $config, array $expected): void
    {
        self::assertSame($expected, ConfigChanges::fromConfig($config));
    }

    public static function provideConfigs(): Generator
    {
        yield 'default' => ['{"mutators": {"@default": true}}', []];

        yield 'default with other settings' => ['{"mutators": {"@default": true}, "minMsi": 50}', []];

        yield 'no mutators' => ['{"minMsi": 50}', []];

        yield 'mutators of the wrong type' => ['{"mutators": true}', []];

        yield 'invalid JSON' => ['{', []];

        yield 'not an object' => ['"mutators"', []];

        yield 'mutators turned on and off' => [
            '{"mutators": {"@default": false, "@boolean": true, "TrueValue": false}}',
            ['-@default', '+@boolean', '-TrueValue'],
        ];

        yield 'settings of mutators' => [
            '{"mutators": {"@default": true, "ArrayItemRemoval": {"remove": "all", "limit": 2}, "global-ignoreSourceCodeByRegex": ["Assert::.*"]}}',
            ['ArrayItemRemoval.remove', 'ArrayItemRemoval.limit', 'global-ignoreSourceCodeByRegex.0'],
        ];

        yield 'value of another type' => ['{"mutators": {"Plus": 1}}', ['Plus']];
    }
}
