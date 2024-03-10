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
use function file_exists;
use function file_get_contents;
use function sprintf;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

class Runner
{
    public const CURRENT_INFECTION_VERSION = '0.27.10';
    public const CURRENT_PHPUNIT_VERSION = '10.5.10';
    public const CURRENT_PHP_VERSION = '8.2.13';

    private const PROCESS_TIMEOUT_SEC = 30;

    private Filesystem $filesystem;

    private ClassesExtractor $classesExtractor;

    public function __construct(Filesystem $filesystem, ClassesExtractor $classesExtractor)
    {
        $this->filesystem = $filesystem;
        $this->classesExtractor = $classesExtractor;
    }

    public function run(string $idHash, string $code, string $test, string $config): RunResult
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

        $extractedClasses = $this->classesExtractor->extract($code);

        foreach ($extractedClasses as $extractedClass) {
            $this->filesystem->dumpFile(sprintf('%s/%s.php', $srcDir, $extractedClass->getClassName()), $extractedClass->getCode());
        }

        try {
            $process = new Process(['php', '--define', 'memory_limit=100M', '../infection.phar', '--log-verbosity=all', '--ansi', '--no-progress'], $rootDir);
            $process->setTimeout(self::PROCESS_TIMEOUT_SEC);

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
                    self::PROCESS_TIMEOUT_SEC
                )
            );
        }
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
