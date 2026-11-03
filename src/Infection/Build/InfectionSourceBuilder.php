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

namespace App\Infection\Build;

use App\Infection\ProcessEnvironment;
use function fclose;
use function flock;
use function fopen;
use const LOCK_EX;
use RuntimeException;
use function sprintf;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Exception\RuntimeException as ProcessException;
use Symfony\Component\Process\Process;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use ZipArchive;

/**
 * Builds Infection from the sources of a commit: the archive of the commit and its Composer dependencies,
 * without the PHAR, which Box would take minutes to compile. Each commit is built once,
 * in infection-builds/_commits/<sha>: "_" is not in the alphabet of the ids of the runs.
 *
 * Composer runs neither scripts nor plugins: nothing of the commit runs before Infection itself.
 */
class InfectionSourceBuilder
{
    public const BUILD_TIMEOUT_SEC = 120;

    private const DOWNLOAD_TIMEOUT_SEC = 30;

    private const ARCHIVE_URL = 'https://codeload.github.com/' . InfectionCommit::REPOSITORY . '/zip/%s';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly Filesystem $filesystem,
        #[Autowire('%kernel.project_dir%/infection-builds/_commits')]
        private readonly string $buildsDir,
        #[Autowire('composer')]
        private readonly string $composerBinary,
    ) {
    }

    /**
     * @phpstan-impure another run can build the commit meanwhile
     */
    public function isBuilt(string $sha): bool
    {
        return $this->filesystem->exists($this->getBinaryPath($sha));
    }

    /**
     * @throws RuntimeException when the commit cannot be built
     *
     * @return string the path of bin/infection of the commit
     */
    public function build(InfectionCommit $commit): string
    {
        if ($this->isBuilt($commit->sha)) {
            return $this->getBinaryPath($commit->sha);
        }

        $this->filesystem->mkdir($this->buildsDir);

        // two runs of the same commit at the same time: the second one waits for the first build.
        // The lock is released when $lock is freed, on an exception too.
        $lock = fopen(sprintf('%s/%s.lock', $this->buildsDir, $commit->sha), 'c');

        // a lock in the directory the builds are written to does not fail
        /* @infection-ignore-all */
        if ($lock === false || !flock($lock, LOCK_EX)) {
            throw new RuntimeException('The build of the commit could not be locked.');
        }

        if (!$this->isBuilt($commit->sha)) {
            $this->buildInTemporaryDirectory($commit->sha);
        }

        fclose($lock);

        return $this->getBinaryPath($commit->sha);
    }

    /**
     * Only one build of the commit runs at a time, see the lock: the temporary directory needs no unique name.
     */
    private function buildInTemporaryDirectory(string $sha): void
    {
        $temporaryDir = sprintf('%s.tmp', $this->getBuildDir($sha));

        try {
            $sourceDir = $this->extractArchive($sha, $temporaryDir);
            $this->installDependencies($sourceDir);

            // only a complete build gets the directory of the commit
            $this->filesystem->rename($sourceDir, $this->getBuildDir($sha));
        } finally {
            $this->filesystem->remove($temporaryDir);
        }
    }

    private function extractArchive(string $sha, string $temporaryDir): string
    {
        $archivePath = sprintf('%s/source.zip', $temporaryDir);

        try {
            $response = $this->httpClient->request('GET', sprintf(self::ARCHIVE_URL, $sha), ['timeout' => self::DOWNLOAD_TIMEOUT_SEC]);

            $this->filesystem->dumpFile($archivePath, $response->getContent());
        } catch (ExceptionInterface $exception) {
            throw new RuntimeException(sprintf('The sources of the commit could not be downloaded: %s', $exception->getMessage()), previous: $exception);
        }

        $zip = new ZipArchive();

        if ($zip->open($archivePath) !== true || !$zip->extractTo($temporaryDir)) {
            throw new RuntimeException('The sources of the commit could not be extracted.');
        }

        // GitHub puts the sources in "<repository>-<sha>"
        return sprintf('%s/infection-%s', $temporaryDir, $sha);
    }

    private function installDependencies(string $sourceDir): void
    {
        $environment = ProcessEnvironment::create();
        // the cache of the packages, shared by the builds of all commits
        $environment['COMPOSER_HOME'] = sprintf('%s/.composer', $this->buildsDir);

        $process = new Process(
            [
                $this->composerBinary,
                'install',
                '--no-dev',
                '--no-scripts',
                '--no-plugins',
                '--no-interaction',
                '--no-progress',
                '--prefer-dist',
                '--classmap-authoritative',
            ],
            $sourceDir,
            $environment,
            timeout: self::BUILD_TIMEOUT_SEC,
        );

        try {
            $process->mustRun();
        } catch (ProcessException $exception) {
            throw new RuntimeException(sprintf('The dependencies of the commit could not be installed: %s', $process->getErrorOutput()), previous: $exception);
        }
    }

    private function getBuildDir(string $sha): string
    {
        return sprintf('%s/%s', $this->buildsDir, $sha);
    }

    private function getBinaryPath(string $sha): string
    {
        return sprintf('%s/bin/infection', $this->getBuildDir($sha));
    }
}
