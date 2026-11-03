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
use App\Infection\RequestTimeLimit;
use function array_slice;
use function arsort;
use function fclose;
use FilesystemIterator;
use function flock;
use function fopen;
use function json_encode;
use const JSON_THROW_ON_ERROR;
use const LOCK_EX;
use RuntimeException;
use SplFileInfo;
use function sprintf;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Exception\RuntimeException as ProcessException;
use Symfony\Component\Process\Process;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use function time;
use ZipArchive;

/**
 * Builds Infection from the sources of a commit: the archive of the commit and its Composer dependencies,
 * without the PHAR, which Box would take minutes to compile. Each commit is built once, in var/infection-commits/<sha>.
 *
 * Not in infection-builds/, where the runs write: bin/infection first looks for an autoloader in
 * bin/../../../autoload.php, which is infection-builds/autoload.php from infection-builds/_commits/<sha>/bin,
 * and /app/autoload.php from var/infection-commits/<sha>/bin, which the web server cannot write.
 *
 * Composer runs neither scripts nor plugins: nothing of the commit runs before Infection itself.
 *
 * One build runs at a time, and any commit since the release can be built, about 15 MB each:
 * only the builds used last are kept, see prune().
 */
class InfectionSourceBuilder
{
    public const MAX_BUILDS = 30;

    private const DOWNLOAD_TIMEOUT_SEC = 30;

    private const COMPOSER_TIMEOUT_SEC = 120;

    // longer than a run, its build included: a build used more recently may be running
    private const IN_USE_SEC = 600;

    private const ARCHIVE_URL = 'https://codeload.github.com/' . InfectionCommit::REPOSITORY . '/zip/%s';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly Filesystem $filesystem,
        #[Autowire('%kernel.project_dir%/var/infection-commits')]
        private readonly string $buildsDir,
        #[Autowire('composer')]
        private readonly string $composerBinary,
        // the GitHub token of Composer, empty for none: without one, GitHub limits the downloads of the packages
        #[Autowire('%env(GITHUB_TOKEN)%')]
        private readonly string $gitHubToken = '',
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
            $this->markUsed($commit->sha);

            return $this->getBinaryPath($commit->sha);
        }

        $this->filesystem->mkdir($this->buildsDir);

        // the request may wait for the build another run is making, then make its own
        RequestTimeLimit::extend(2 * (self::DOWNLOAD_TIMEOUT_SEC + self::COMPOSER_TIMEOUT_SEC));

        // one build at a time: the second of two runs of the same commit waits for the build of the first one.
        // The lock is released when $lock is freed, on an exception too.
        $lock = fopen($this->buildsDir . '/build.lock', 'c');

        // a lock in the directory the builds are written to does not fail
        /* @infection-ignore-all */
        if ($lock === false || !flock($lock, LOCK_EX)) {
            throw new RuntimeException('The build of the commit could not be locked.');
        }

        // the build of another run, or this one: its directory was just made, which marks it used
        if (!$this->isBuilt($commit->sha)) {
            $this->buildInTemporaryDirectory($commit->sha);
        }

        fclose($lock);

        $this->prune();

        return $this->getBinaryPath($commit->sha);
    }

    /**
     * The modification time of the directory of a build is the time it was used last.
     */
    private function markUsed(string $sha): void
    {
        $this->filesystem->touch($this->getBuildDir($sha));
    }

    /**
     * Removes the builds used least recently beyond MAX_BUILDS, except the ones used in the last
     * IN_USE_SEC: a run may be running them. A removed commit is built again by its next run.
     */
    private function prune(): void
    {
        $lastUses = [];

        foreach (new FilesystemIterator($this->buildsDir) as $file) {
            /** @var SplFileInfo $file */
            if ($file->isDir() && InfectionCommit::isSha($file->getFilename())) {
                $lastUses[$file->getPathname()] = $file->getMTime();
            }
        }

        arsort($lastUses);

        foreach (array_slice($lastUses, self::MAX_BUILDS, preserve_keys: true) as $buildDir => $lastUse) {
            // a second more or less at IN_USE_SEC does not matter
            /* @infection-ignore-all */
            if ($lastUse < time() - self::IN_USE_SEC) {
                $this->remove($buildDir);
            }
        }
    }

    /**
     * Two runs can prune the same build at the same time: the build of the commit is done, and the
     * next build prunes again, so a removal that fails is not an error of the run.
     */
    private function remove(string $buildDir): void
    {
        try {
            $this->filesystem->remove($buildDir);
        } catch (IOException) {
        }
    }

    private function buildInTemporaryDirectory(string $sha): void
    {
        // only one build runs at a time, see the lock: what is left is of a request that died during its build
        $temporaryDir = sprintf('%s.tmp', $this->getBuildDir($sha));
        $this->filesystem->remove($temporaryDir);

        try {
            $sourceDir = $this->extractArchive($sha, $temporaryDir);
            $this->installDependencies($sourceDir);

            if (!$this->filesystem->exists($sourceDir . '/bin/infection')) {
                throw new RuntimeException('The commit has no bin/infection.');
            }

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
            $response = $this->httpClient->request('GET', sprintf(self::ARCHIVE_URL, $sha), ['max_duration' => self::DOWNLOAD_TIMEOUT_SEC]);

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

        if ($this->gitHubToken !== '') {
            // only Composer gets the token, not Infection
            $environment['COMPOSER_AUTH'] = json_encode(['github-oauth' => ['github.com' => $this->gitHubToken]], JSON_THROW_ON_ERROR);
        }

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
            timeout: self::COMPOSER_TIMEOUT_SEC,
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
