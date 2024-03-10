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

namespace App\Tests\Unit\Infection;

use App\Infection\ConfigBuilder;
use function array_merge;
use Generator;
use function json_decode;
use PHPUnit\Framework\TestCase;

final class ConfigBuilderTest extends TestCase
{
    private ConfigBuilder $configBuilder;

    protected function setUp(): void
    {
        parent::setUp();

        $this->configBuilder = new ConfigBuilder();
    }

    /**
     * @dataProvider provideConfigs
     *
     * @param array<string, mixed> $expectedConfig
     */
    public function test_it_merges_required_fields(string $originalConfig, array $expectedConfig): void
    {
        $resultConfig = $this->configBuilder->build($originalConfig);

        $this->assertSame($expectedConfig, json_decode($resultConfig, true));
    }

    public static function provideConfigs(): Generator
    {
        yield 'Empty object provided' => [
            '{}',
            ConfigBuilder::NOT_OVERRIDABLE_KEYS,
        ];

        yield 'Default profile is enabled' => [
            <<<JSON
{
    "mutators": {
        "@default": true
    }
}
JSON
            ,
            array_merge(
                ConfigBuilder::NOT_OVERRIDABLE_KEYS,
                [
                    'mutators' => ['@default' => true],
                ]
            ),
        ];

        yield 'Bootstrap does not override system value' => [
            <<<JSON
{
    "bootstrap": "\/path\/to\/bootstrap.php"
}
JSON
            ,
            ConfigBuilder::NOT_OVERRIDABLE_KEYS,
        ];

        yield 'Timeout does not override system value' => [
            <<<JSON
{
    "timeout": 33
}
JSON
            ,
            ConfigBuilder::NOT_OVERRIDABLE_KEYS,
        ];

        yield 'Source does not override system value' => [
            <<<JSON
{
    "source": {"directories": ["src"]}
}
JSON
            ,
            ConfigBuilder::NOT_OVERRIDABLE_KEYS,
        ];

        yield 'PHPUnit setting does not override system value' => [
            <<<JSON
{
    "phpUnit": {"customPath": "\/path\/to\/folder\/phpunit.phar"}
}
JSON
            ,
            ConfigBuilder::NOT_OVERRIDABLE_KEYS,
        ];

        yield 'tmpDir setting does not override system value' => [
            <<<JSON
{
    "tmpDir": "tmp"
}
JSON
            ,
            ConfigBuilder::NOT_OVERRIDABLE_KEYS,
        ];

        yield 'logs setting does not override system value' => [
            <<<JSON
{
    "logs": {"text": "text.log"}
}
JSON
            ,
            ConfigBuilder::NOT_OVERRIDABLE_KEYS,
        ];

        yield 'Custom allowed settings are present in the final config' => [
            <<<JSON
{
    "mutators": {"ArrayItem": false, "PublicVisibility": true},
    "minMsi": 100
}
JSON
            ,
            array_merge(
                ConfigBuilder::NOT_OVERRIDABLE_KEYS,
                [
                    'mutators' => ['ArrayItem' => false, 'PublicVisibility' => true],
                    'minMsi' => 100,
                ]
            ),
        ];
    }
}
