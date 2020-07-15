<?php

declare(strict_types=1);

namespace App\Infection;

use function json_decode;
use function json_encode;
use const JSON_THROW_ON_ERROR;

/**
 * This class assumes that json was already validated by Infection's JSON schema.
 * The goal of this class is to set not overridable keys, and add user defined ones.
 */
final class ConfigBuilder
{
    public const NOT_OVERRIDABLE_KEYS = [
        'bootstrap' => './autoload.php',
        'timeout' => self::TIMEOUT,
        'source' => ['directories' => ['src']],
        'phpUnit' => ['customPath' => '../phpunit.phar'],
        'tmpDir' => '.',
        'logs' => ['json' => 'infection.log.json'],
    ];

    private const TIMEOUT = 3;

    public function build(string $userProvidedConfig): string
    {
        $decodedOriginalConfig = json_decode($userProvidedConfig, true, JSON_THROW_ON_ERROR);

        return (string) json_encode(self::NOT_OVERRIDABLE_KEYS + $decodedOriginalConfig);
    }
}
