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

use App\Code\ClassesExtractor\ClassesExtractor;
use function array_keys;
use function file_exists;
use function file_get_contents;
use function getenv;
use function ini_get;
use const PHP_MAJOR_VERSION;
use const PHP_MINOR_VERSION;
use const PHP_VERSION;
use function set_time_limit;
use function sprintf;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

class Runner
{
    public const CURRENT_INFECTION_VERSION = '0.35.6';
    public const CURRENT_PHPUNIT_VERSION = '13.4.1';
    // the PHP binary the playground runs Infection with, i.e. the one of the Docker image
    public const CURRENT_PHP_VERSION = PHP_VERSION;
    // the Mago binary of the Docker image, see the mago stage of the Dockerfile
    public const CURRENT_MAGO_VERSION = '1.57.0';
    // the PHPStan PHAR of the Docker image, see the phpstan stage of the Dockerfile
    public const CURRENT_PHPSTAN_VERSION = '2.3.1';

    private const PROCESS_TIMEOUT_SEC = 30;
    // Infection starts PHPStan once per escaped mutant, one after the other: on the droplet
    // a handful of escaped mutants take longer than 30 seconds
    private const PHPSTAN_PROCESS_TIMEOUT_SEC = 90;
    // what the request does after the Infection process: saving the result and redirecting
    private const REQUEST_TIME_MARGIN_SEC = 30;

    /**
     * PHP_INI_SCAN_DIR loads the ini file with disable_functions for the playground.
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

    private Filesystem $filesystem;

    private ClassesExtractor $classesExtractor;

    public function __construct(Filesystem $filesystem, ClassesExtractor $classesExtractor)
    {
        $this->filesystem = $filesystem;
        $this->classesExtractor = $classesExtractor;
    }

    public function run(string $idHash, string $code, string $test, string $config, ?StaticAnalysisTool $staticAnalysisTool = null): RunResult
    {
        $rootDir = __DIR__ . '/../../infection-builds/' . $idHash;

        $srcDir = sprintf('%s/src', $rootDir);
        $testsDir = sprintf('%s/tests', $rootDir);

        $this->filesystem->mkdir($srcDir);
        $this->filesystem->mkdir($testsDir);

        $this->filesystem->dumpFile(sprintf('%s/phpunit.xml', $rootDir), $this->getPhpUnitXmlConfig());

        $this->filesystem->dumpFile(sprintf('%s/infection.json', $rootDir), $config);
        $this->filesystem->dumpFile(sprintf('%s/autoload.php', $rootDir), $this->getAutoload());
        $this->filesystem->dumpFile(sprintf('%s/SourceClassTest.php', $testsDir), $test);

        if ($staticAnalysisTool === StaticAnalysisTool::Mago) {
            // Infection does not start Mago without a configuration file
            $this->filesystem->dumpFile(sprintf('%s/mago.toml', $rootDir), $this->getMagoConfig());
        }

        if ($staticAnalysisTool === StaticAnalysisTool::PHPStan) {
            // Infection does not start PHPStan without a configuration file
            $this->filesystem->dumpFile(sprintf('%s/phpstan.neon', $rootDir), $this->getPhpStanConfig());
        }

        $extractedClasses = $this->classesExtractor->extract($code);

        foreach ($extractedClasses as $extractedClass) {
            $this->filesystem->dumpFile(sprintf('%s/%s.php', $srcDir, $extractedClass->getClassName()), $extractedClass->getCode());
        }

        $processTimeout = self::getProcessTimeoutSec($staticAnalysisTool);
        self::extendRequestTimeLimit($processTimeout);

        try {
            $process = new Process([
                'php',
                '--define',
                'memory_limit=100M',
                '../infection.phar',
                '--log-verbosity=all',
                '--ansi',
                '--no-progress',
                '--show-mutations=0',
                '--with-uncovered',
            ], $rootDir, self::buildEnvironment());
            $process->setTimeout($processTimeout);

            $process->run();
            // todo download if not present infection/phpunit (cache warmer) ?
            // todo remove tmp folder

            $infectionJsonLogPath = sprintf('%s/infection.log.json', $rootDir);

            return new RunResult(
                $process->getOutput() . $process->getErrorOutput(),
                file_exists($infectionJsonLogPath) ? (string) file_get_contents($infectionJsonLogPath) : null
            );
        } catch (ProcessTimedOutException $e) {
            return RunResult::fromError(
                sprintf(
                    'Infection process exceeded the timeout of %d seconds. Please check your code or report an issue',
                    $processTimeout
                )
            );
        }
    }

    public static function getProcessTimeoutSec(?StaticAnalysisTool $staticAnalysisTool): int
    {
        return $staticAnalysisTool === StaticAnalysisTool::PHPStan
            ? self::PHPSTAN_PROCESS_TIMEOUT_SEC
            : self::PROCESS_TIMEOUT_SEC;
    }

    /**
     * FrankenPHP counts max_execution_time in wall-clock time, waiting for the Infection process included.
     * With a limit not longer than the process timeout, the request dies with a fatal error, a 500,
     * before the ProcessTimedOutException is caught.
     */
    private static function extendRequestTimeLimit(int $processTimeout): void
    {
        // 0 is no limit, e.g. in the CLI: setting one would end the process later
        if ((int) ini_get('max_execution_time') === 0) {
            return;
        }

        // restarts the count from now
        set_time_limit($processTimeout + self::REQUEST_TIME_MARGIN_SEC);
    }

    /**
     * The submitted code runs in this process: it must not see the environment of the app,
     * which holds DATABASE_URL and APP_SECRET. Symfony Process passes it on by default,
     * so every variable that is not needed to run PHP is removed.
     *
     * @return array<string, string|false>
     */
    private static function buildEnvironment(): array
    {
        $environment = [];

        foreach (array_keys($_ENV + getenv()) as $name) {
            $environment[(string) $name] = false;
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

    private function getPhpUnitXmlConfig(): string
    {
        return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<phpunit backupGlobals="false"
         bootstrap="./autoload.php"
         colors="true"
         processIsolation="false"
         stopOnFailure="true"
>
    <testsuites>
        <testsuite name="Application Test Suite">
            <directory>./tests/</directory>
        </testsuite>
    </testsuites>
    
    <coverage />

    <source>
        <include>
            <directory>./src/</directory>
        </include>
    </source>
</phpunit>
XML;
    }

    /**
     * Mago analyses the classes only: the tests use PHPUnit, which it does not see.
     */
    private function getMagoConfig(): string
    {
        $phpVersion = sprintf('%d.%d', PHP_MAJOR_VERSION, PHP_MINOR_VERSION);

        return <<<TOML
php-version = "{$phpVersion}"

[source]
paths = ["src"]
TOML;
    }

    /**
     * PHPStan analyses the classes only, like Mago. Its cache stays in the directory of the run.
     */
    private function getPhpStanConfig(): string
    {
        return <<<'NEON'
parameters:
    level: max
    paths:
        - src
    tmpDir: var/phpstan
    parallel:
        maximumNumberOfProcesses: 1
NEON;
    }

    private function getAutoload(): string
    {
        return <<<'PHP'
<?php

use Infected\Psr4AutoloaderClass;

require_once __DIR__ . '/../Psr4AutoloaderClass.php';

$loader = new Psr4AutoloaderClass();

// register the autoloader
$loader->register();

// register the base directories for the namespace prefix
$loader->addNamespace('Infected', __DIR__ . '/src');
$loader->addNamespace('Infected\Tests', __DIR__ . '/tests');
PHP;
    }
}
