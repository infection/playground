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

use function array_keys;
use function getenv;
use function is_file;
use RuntimeException;
use function sprintf;

/**
 * The environment of the processes the playground starts.
 */
final class ProcessEnvironment
{
    /**
     * The ini file with disable_functions for the submitted code, see the Dockerfile. PHP does not scan
     * this directory by default: the web server and the tools, e.g. Composer, need curl_multi_exec.
     */
    public const SANDBOX_INI_DIR = '/usr/local/etc/php/playground.conf.d';

    private const SANDBOX_INI_FILE = 'playground.ini';

    /**
     * PHP_INI_SCAN_DIR loads the ini files of the app, and the extensions of the image.
     */
    private const INHERITED_ENVIRONMENT_VARIABLES = [
        'PATH',
        'HOME',
        'LANG',
        'TZ',
        'PHP_INI_DIR',
        'PHP_INI_SCAN_DIR',
        'XDEBUG_MODE',
    ];

    /**
     * For the processes that run the submitted code: Infection, and what it starts with the same environment
     * (PHPUnit, the mutants, PHPStan, Mago). They read the ini file of the sandbox on top of the ones of the app.
     *
     * @throws RuntimeException without the ini file of the sandbox: the code never runs without it
     *
     * @return array<string, string|false>
     */
    public static function createSandboxed(string $sandboxIniDir = self::SANDBOX_INI_DIR): array
    {
        if (!is_file(sprintf('%s/%s', $sandboxIniDir, self::SANDBOX_INI_FILE))) {
            throw new RuntimeException(sprintf('The ini file of the sandbox is missing in %s.', $sandboxIniDir));
        }

        $environment = self::create();
        // the directories are separated by ":", and an empty one, also without PHP_INI_SCAN_DIR, is the default one of PHP
        $environment['PHP_INI_SCAN_DIR'] = sprintf('%s:%s', getenv('PHP_INI_SCAN_DIR'), $sandboxIniDir);

        return $environment;
    }

    /**
     * The submitted code runs in these processes: they must not see the environment of the app,
     * which holds DATABASE_URL and APP_SECRET. Symfony Process passes it on by default,
     * so every variable that is not needed to run PHP is removed.
     *
     * @return array<string, string|false>
     */
    public static function create(): array
    {
        $environment = [];

        /** @var array<string, mixed> $variables */
        $variables = $_ENV + getenv();

        foreach (array_keys($variables) as $name) {
            $environment[$name] = false;
        }

        foreach (self::INHERITED_ENVIRONMENT_VARIABLES as $name) {
            $value = getenv($name);

            if ($value !== false) {
                $environment[$name] = $value;
            }
        }

        // the PHAR's requirement checker is not compatible with PHP 8.5 and spams deprecations;
        // the Docker image always satisfies the requirements
        $environment['BOX_REQUIREMENT_CHECKER'] = '0';

        return $environment;
    }
}
