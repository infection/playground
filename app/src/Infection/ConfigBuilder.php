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

namespace App\Infection;

use App\Code\Validator\CodeValidator;
use function implode;
use function json_decode;
use function json_encode;
use const JSON_THROW_ON_ERROR;
use function sprintf;
use function str_starts_with;
use function strlen;
use function substr;

/**
 * This class assumes that json was already validated by Infection's JSON schema.
 * The goal of this class is to set not overridable keys, and add user defined ones.
 */
final class ConfigBuilder
{
    private const TIMEOUT = 3;

    private const NAMESPACE = 'Infected';

    public function build(string $userProvidedConfig): string
    {
        $basicConfig = self::getNotOverridableBaseConfig();

        /** @var array{mutators?: array<string, bool>} $decodedOriginalConfig */
        $decodedOriginalConfig = json_decode($userProvidedConfig, true, JSON_THROW_ON_ERROR);

        if ($this->containsCustomMutator($decodedOriginalConfig['mutators'] ?? [])) {
            // @phpstan-ignore-next-line
            $basicConfig['source']['excludes'] = $this->findCustomMutators($decodedOriginalConfig['mutators'] ?? []);
        }

        return (string) json_encode($basicConfig + $decodedOriginalConfig);
    }

    /**
     * @return array<string, mixed>
     */
    public static function getNotOverridableBaseConfig(): array
    {
        return [
            'bootstrap' => './autoload.php',
            'timeout' => self::TIMEOUT,
            'source' => ['directories' => ['src']],
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
        ];
    }

    /**
     * @param array<string, bool> $mutators
     */
    private function containsCustomMutator(array $mutators): bool
    {
        foreach ($mutators as $mutatorName => $mutatorConfig) {
            if ($this->isCustomMutatorName($mutatorName)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, bool> $mutators
     *
     * @return list<string>
     */
    private function findCustomMutators(array $mutators): array
    {
        $customMutatorNames = [];

        foreach ($mutators as $mutatorName => $mutatorConfig) {
            if ($this->isCustomMutatorName($mutatorName)) {
                $customMutatorNames[] = substr($mutatorName, strlen($this->getStaticNamespace()));
            }
        }

        return $customMutatorNames;
    }

    private function getStaticNamespace(): string
    {
        return sprintf('%s\\', self::NAMESPACE);
    }

    private function isCustomMutatorName(string $mutatorName): bool
    {
        return str_starts_with($mutatorName, $this->getStaticNamespace());
    }
}
