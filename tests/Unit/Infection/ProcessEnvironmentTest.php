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

use App\Infection\ProcessEnvironment;
use function getenv;
use PHPUnit\Framework\TestCase;
use function putenv;
use RuntimeException;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;
use function sys_get_temp_dir;
use function uniqid;

final class ProcessEnvironmentTest extends TestCase
{
    private ?string $sandboxIniDir = null;

    protected function tearDown(): void
    {
        putenv('APP_SECRET_OF_THE_TEST');

        if ($this->sandboxIniDir !== null) {
            (new Filesystem())->remove($this->sandboxIniDir);
        }
    }

    public function test_the_processes_do_not_see_the_environment_of_the_app(): void
    {
        putenv('APP_SECRET_OF_THE_TEST=secret');

        $environment = ProcessEnvironment::create();

        self::assertFalse($environment['APP_SECRET_OF_THE_TEST']);
    }

    public function test_the_processes_get_what_php_needs(): void
    {
        $environment = ProcessEnvironment::create();

        self::assertSame(getenv('PATH'), $environment['PATH']);
        self::assertNotFalse($environment['PATH']);
        self::assertSame('0', $environment['BOX_REQUIREMENT_CHECKER']);
    }

    public function test_the_sandboxed_processes_read_the_ini_file_of_the_sandbox_too(): void
    {
        $environment = ProcessEnvironment::createSandboxed($this->createSandboxIniDir());

        self::assertSame(getenv('PHP_INI_SCAN_DIR') . ':' . $this->sandboxIniDir, $environment['PHP_INI_SCAN_DIR']);
        self::assertFalse($environment['APP_SECRET_OF_THE_TEST'] ?? false);
    }

    public function test_without_ini_files_of_the_app_the_sandboxed_processes_read_the_default_ones_of_php(): void
    {
        $originalScanDir = getenv('PHP_INI_SCAN_DIR');
        putenv('PHP_INI_SCAN_DIR');

        try {
            $environment = ProcessEnvironment::createSandboxed($this->createSandboxIniDir());
        } finally {
            putenv($originalScanDir === false ? 'PHP_INI_SCAN_DIR' : 'PHP_INI_SCAN_DIR=' . $originalScanDir);
        }

        // an empty directory is the default one of PHP
        self::assertSame(':' . $this->sandboxIniDir, $environment['PHP_INI_SCAN_DIR']);
    }

    public function test_the_submitted_code_never_runs_without_the_ini_file_of_the_sandbox(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('The ini file of the sandbox is missing in /nowhere.');

        ProcessEnvironment::createSandboxed('/nowhere');
    }

    /**
     * The image has the ini file of the sandbox where the processes look for it, see the Dockerfile.
     */
    public function test_a_sandboxed_process_cannot_call_the_disabled_functions(): void
    {
        $process = new Process(['php', '-r', 'echo ini_get("disable_functions");'], null, ProcessEnvironment::createSandboxed());
        $process->mustRun();

        self::assertSame('exec,passthru,system,popen,curl_exec,curl_multi_exec,parse_ini_file,show_source', $process->getOutput());
    }

    public function test_the_other_processes_can(): void
    {
        $process = new Process(['php', '-r', 'echo ini_get("disable_functions");'], null, ProcessEnvironment::create());
        $process->mustRun();

        self::assertSame('', $process->getOutput());
    }

    private function createSandboxIniDir(): string
    {
        $this->sandboxIniDir = sys_get_temp_dir() . '/' . uniqid('sandbox-ini-', true);
        (new Filesystem())->dumpFile($this->sandboxIniDir . '/playground.ini', '');

        return $this->sandboxIniDir;
    }
}
