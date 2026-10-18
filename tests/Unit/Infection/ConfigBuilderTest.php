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

use App\Code\Validator\CodeValidator;
use App\Infection\ConfigBuilder;
use App\Infection\StaticAnalysisTool;
use function array_merge;
use Generator;
use function implode;
use function json_decode;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use function sprintf;

final class ConfigBuilderTest extends TestCase
{
    private ConfigBuilder $configBuilder;

    protected function setUp(): void
    {
        parent::setUp();

        $this->configBuilder = new ConfigBuilder();
    }

    /**
     * @param array<string, mixed> $expectedConfig
     */
    #[DataProvider('provideConfigs')]
    public function test_it_merges_required_fields(string $originalConfig, array $expectedConfig): void
    {
        $resultConfig = $this->configBuilder->build($originalConfig);

        self::assertSame($expectedConfig, json_decode($resultConfig, true));
    }

    public static function provideConfigs(): Generator
    {
        yield 'Empty object provided' => [
            '{}',
            ConfigBuilder::getNotOverridableBaseConfig(),
        ];

        yield 'Default profile is enabled' => [
            <<<JSON
{
    "mutators": {
        "@default": true
    }
}
JSON,
            array_merge(
                ConfigBuilder::getNotOverridableBaseConfig(),
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
JSON,
            ConfigBuilder::getNotOverridableBaseConfig(),
        ];

        yield 'Timeout does not override system value' => [
            <<<JSON
{
    "timeout": 33
}
JSON,
            ConfigBuilder::getNotOverridableBaseConfig(),
        ];

        yield 'Source does not override system value' => [
            <<<JSON
{
    "source": {"directories": ["src"]}
}
JSON,
            ConfigBuilder::getNotOverridableBaseConfig(),
        ];

        yield 'PHPUnit setting does not override system value' => [
            <<<JSON
{
    "phpUnit": {"customPath": "\/path\/to\/folder\/phpunit.phar"}
}
JSON,
            ConfigBuilder::getNotOverridableBaseConfig(),
        ];

        yield 'tmpDir setting does not override system value' => [
            <<<JSON
{
    "tmpDir": "tmp"
}
JSON,
            ConfigBuilder::getNotOverridableBaseConfig(),
        ];

        yield 'logs setting does not override system value' => [
            <<<JSON
{
    "logs": {"text": "text.log"}
}
JSON,
            ConfigBuilder::getNotOverridableBaseConfig(),
        ];

        yield 'Custom allowed settings are present in the final config' => [
            <<<JSON
{
    "mutators": {"ArrayItem": false, "PublicVisibility": true},
    "minMsi": 100
}
JSON,
            array_merge(
                ConfigBuilder::getNotOverridableBaseConfig(),
                [
                    'mutators' => ['ArrayItem' => false, 'PublicVisibility' => true],
                    'minMsi' => 100,
                ]
            ),
        ];

        yield 'Custom mutators are automatically excluded from mutation' => [
            <<<'JSON'
{
    "mutators": {"@default": false, "Infected\\CustomMutator1": true, "Infected\\CustomMutator2": true}
}
JSON,
            [
                'bootstrap' => './autoload.php',
                'timeout' => 3,
                'source' => [
                    'directories' => ['src'],
                    'excludes' => [
                        'CustomMutator1',
                        'CustomMutator2',
                    ],
                ],
                'phpUnit' => ['customPath' => '../phpunit.phar'],
                'tmpDir' => '.',
                'logs' => ['json' => 'infection.log.json'],
                'initialTestsPhpOptions' => sprintf(
                    '--define disable_functions=%s',
                    implode(
                        ',',
                        CodeValidator::getListOfDisabledFunctionsOnPhpLevel()
                    )
                ),
                'mutators' => [
                    '@default' => false,
                    'Infected\\CustomMutator1' => true,
                    'Infected\\CustomMutator2' => true,
                ],
            ],
        ];
    }

    public function test_it_runs_mago_from_the_docker_image(): void
    {
        $resultConfig = $this->configBuilder->build('{"mutators": {"@default": true}}', StaticAnalysisTool::Mago);

        self::assertSame(
            array_merge(
                ConfigBuilder::getNotOverridableBaseConfig(),
                [
                    'staticAnalysisTool' => 'mago',
                    'mago' => ['customPath' => '/usr/local/bin/mago'],
                    'mutators' => ['@default' => true],
                ]
            ),
            json_decode($resultConfig, true)
        );
    }

    public function test_it_runs_phpstan_from_the_docker_image(): void
    {
        $resultConfig = $this->configBuilder->build('{"mutators": {"@default": true}}', StaticAnalysisTool::PHPStan);

        self::assertSame(
            array_merge(
                ConfigBuilder::getNotOverridableBaseConfig(),
                [
                    'timeout' => 10,
                    'staticAnalysisTool' => 'phpstan',
                    'phpStan' => ['customPath' => '/usr/local/bin/phpstan.phar'],
                    'mutators' => ['@default' => true],
                ]
            ),
            json_decode($resultConfig, true)
        );
    }

    public function test_it_does_not_run_static_analysis_by_default(): void
    {
        $resultConfig = (array) json_decode($this->configBuilder->build('{}', null), true);

        self::assertArrayNotHasKey('staticAnalysisTool', $resultConfig);
        self::assertArrayNotHasKey('mago', $resultConfig);
        self::assertArrayNotHasKey('phpStan', $resultConfig);
    }
}
