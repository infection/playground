<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infection;

use App\Infection\ConfigBuilder;
use function array_merge;
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

        self::assertSame($expectedConfig, json_decode($resultConfig, true));
    }

    public function provideConfigs(): \Generator
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
