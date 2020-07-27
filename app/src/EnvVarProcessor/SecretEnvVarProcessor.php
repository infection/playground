<?php

declare(strict_types=1);

namespace App\EnvVarProcessor;

use Symfony\Component\DependencyInjection\EnvVarProcessorInterface;
use Symfony\Component\DependencyInjection\Exception\EnvNotFoundException;
use Symfony\Component\DependencyInjection\Exception\RuntimeException;

class SecretEnvVarProcessor implements EnvVarProcessorInterface
{
    /**
     * If {ENV}_FILE environment variable is set, try to read value from file.
     * Else return value from {ENV} environment variable.
     */
    public function getEnv($prefix, $name, \Closure $getEnv): ?string
    {
        $fileVar = sprintf('%s_FILE', $name);

        try {
            return trim($getEnv(sprintf('file:%s', $fileVar)));
        } catch (EnvNotFoundException $fileException) {
            return $this->readEnvironmentVariable($name, $fileVar, $getEnv);
        }
    }

    public static function getProvidedTypes(): array
    {
        return [
            'secret' => 'string',
        ];
    }

    private function readEnvironmentVariable(string $name, string $fileVar, \Closure $getEnv): string
    {
        try {
            return $getEnv($name);
        } catch (EnvNotFoundException $envException) {
            throw new RuntimeException(sprintf('Environment variable not found: "%s" or "%s"', $name, $fileVar), $envException->getCode(), $envException);
        }
    }
}
