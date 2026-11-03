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

namespace App\Tests\Unit\Infection\Build;

use App\Infection\Build\InfectionCommit;
use App\Infection\Build\InfectionSourceBuilder;
use function array_slice;
use function clearstatcache;
use function file_get_contents;
use function filemtime;
use function ini_get;
use function ini_set;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use function scandir;
use function set_time_limit;
use function sprintf;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use function sys_get_temp_dir;
use function time;
use function uniqid;
use ZipArchive;

final class InfectionSourceBuilderTest extends TestCase
{
    private const SHA = 'dbc3013fc473271b4bd86f5ec103702a19d86e83';

    // records its arguments and COMPOSER_HOME in the sources, and installs the dependencies
    private const COMPOSER = <<<'SH'
        #!/bin/sh
        printf '%s\n' "$@" > composer-arguments
        printf '%s' "$COMPOSER_HOME" > composer-home
        printf '%s' "$COMPOSER_AUTH" > composer-auth
        mkdir vendor
        SH;

    private const FAILING_COMPOSER = <<<'SH'
        #!/bin/sh
        echo 'Your lock file does not contain a compatible set of packages.' >&2
        exit 2
        SH;

    private Filesystem $filesystem;

    private string $testDir;

    private string $buildsDir;

    protected function setUp(): void
    {
        $this->filesystem = new Filesystem();
        $this->testDir = sys_get_temp_dir() . '/' . uniqid('infection-source-builder-', true);
        $this->buildsDir = $this->testDir . '/builds';
    }

    protected function tearDown(): void
    {
        $this->filesystem->remove($this->testDir);
    }

    public function test_it_builds_a_commit_from_its_sources_and_their_dependencies(): void
    {
        $archiveResponse = new MockResponse($this->createArchive(), ['http_code' => 200]);
        $builder = $this->createBuilder(new MockHttpClient([$archiveResponse]), self::COMPOSER);

        $binaryPath = $builder->build(new InfectionCommit(self::SHA));

        $buildDir = $this->buildsDir . '/' . self::SHA;
        self::assertSame($buildDir . '/bin/infection', $binaryPath);
        self::assertSame('#!/usr/bin/env php', file_get_contents($binaryPath));
        self::assertTrue($builder->isBuilt(self::SHA));
        self::assertDirectoryExists($buildDir . '/vendor');
        self::assertSame(
            "install\n--no-dev\n--no-scripts\n--no-plugins\n--no-interaction\n--no-progress\n--prefer-dist\n--classmap-authoritative\n",
            file_get_contents($buildDir . '/composer-arguments'),
        );
        self::assertSame($this->buildsDir . '/.composer', file_get_contents($buildDir . '/composer-home'));
        // no token, no authentication
        self::assertSame('', file_get_contents($buildDir . '/composer-auth'));

        self::assertSame('https://codeload.github.com/infection/infection/zip/' . self::SHA, $archiveResponse->getRequestUrl());
        self::assertSame(30.0, $archiveResponse->getRequestOptions()['max_duration']);

        // the temporary directory is gone
        self::assertSame(['.', '..', 'build.lock', self::SHA], scandir($this->buildsDir));
    }

    public function test_only_composer_gets_the_github_token(): void
    {
        $builder = $this->createBuilder(new MockHttpClient([new MockResponse($this->createArchive(), ['http_code' => 200])]), self::COMPOSER, 'github_pat_test');

        $builder->build(new InfectionCommit(self::SHA));

        self::assertSame(
            '{"github-oauth":{"github.com":"github_pat_test"}}',
            file_get_contents($this->buildsDir . '/' . self::SHA . '/composer-auth'),
        );
    }

    public function test_the_build_restarts_the_request_time_limit(): void
    {
        $originalTimeLimit = ini_get('max_execution_time');
        $builder = $this->createBuilder(new MockHttpClient([new MockResponse($this->createArchive(), ['http_code' => 200])]), self::COMPOSER);

        // the web server has a time limit, the CLI does not
        ini_set('max_execution_time', '30');

        try {
            $builder->build(new InfectionCommit(self::SHA));

            $timeLimit = (int) ini_get('max_execution_time');
        } finally {
            set_time_limit((int) $originalTimeLimit);
        }

        // the download and Composer of the build of another run and of this one, and what the request does after them
        self::assertSame(330, $timeLimit);
    }

    public function test_it_removes_what_a_request_that_died_during_its_build_left(): void
    {
        $this->filesystem->dumpFile(sprintf('%s/%s.tmp/infection-%s/left-by-a-dead-request', $this->buildsDir, self::SHA, self::SHA), '');

        $this->createBuilder(new MockHttpClient([new MockResponse($this->createArchive(), ['http_code' => 200])]), self::COMPOSER)
            ->build(new InfectionCommit(self::SHA));

        self::assertFileDoesNotExist($this->buildsDir . '/' . self::SHA . '/left-by-a-dead-request');
    }

    public function test_it_refuses_a_commit_without_infection(): void
    {
        $builder = $this->createBuilder(new MockHttpClient([new MockResponse($this->createArchive(withBinary: false), ['http_code' => 200])]), self::COMPOSER);

        $this->assertBuildFails($builder, 'The commit has no bin/infection.');
    }

    public function test_a_commit_is_built_once(): void
    {
        $this->filesystem->dumpFile($this->buildsDir . '/' . self::SHA . '/bin/infection', '');
        $httpClient = new MockHttpClient([]);

        $builder = $this->createBuilder($httpClient, self::FAILING_COMPOSER);

        self::assertTrue($builder->isBuilt(self::SHA));
        self::assertSame($this->buildsDir . '/' . self::SHA . '/bin/infection', $builder->build(new InfectionCommit(self::SHA)));
        self::assertSame(0, $httpClient->getRequestsCount());
        // without waiting for the lock of the builds
        self::assertSame(['.', '..', self::SHA], scandir($this->buildsDir));
    }

    public function test_a_cached_build_is_marked_used(): void
    {
        $buildDir = $this->createBuild(self::SHA, time() - 86400);

        $this->createBuilder(new MockHttpClient([]), self::FAILING_COMPOSER)->build(new InfectionCommit(self::SHA));

        clearstatcache();
        self::assertGreaterThan(time() - 60, filemtime($buildDir));
    }

    public function test_a_new_build_removes_the_builds_used_least_recently_beyond_the_maximum(): void
    {
        $oldBuilds = [];

        // the oldest first: the order of the directories is not the order of their use
        for ($i = InfectionSourceBuilder::MAX_BUILDS; $i >= 1; --$i) {
            $oldBuilds[] = $this->createBuild(sprintf('%040x', $i), time() - 86400 - $i);
        }

        $this->filesystem->mkdir($this->buildsDir . '/.composer');
        $this->filesystem->touch($this->buildsDir . '/.composer', time() - 10 * 86400);

        $this->createBuilder(new MockHttpClient([new MockResponse($this->createArchive(), ['http_code' => 200])]), self::COMPOSER)
            ->build(new InfectionCommit(self::SHA));

        // the new build and the MAX_BUILDS - 1 builds used last are kept
        self::assertDirectoryDoesNotExist($oldBuilds[0]);

        foreach (array_slice($oldBuilds, 1) as $oldBuild) {
            self::assertDirectoryExists($oldBuild);
        }
        self::assertDirectoryExists($this->buildsDir . '/' . self::SHA);
        // the cache of Composer and the locks are not builds
        self::assertDirectoryExists($this->buildsDir . '/.composer');
        self::assertFileExists($this->buildsDir . '/build.lock');
    }

    public function test_the_builds_used_in_the_last_minutes_are_kept(): void
    {
        $builds = [];

        for ($i = 1; $i <= InfectionSourceBuilder::MAX_BUILDS; ++$i) {
            // a run may be running them
            $builds[] = $this->createBuild(sprintf('%040x', $i), time() - 60);
        }

        $this->createBuilder(new MockHttpClient([new MockResponse($this->createArchive(), ['http_code' => 200])]), self::COMPOSER)
            ->build(new InfectionCommit(self::SHA));

        foreach ($builds as $build) {
            self::assertDirectoryExists($build);
        }
    }

    public function test_it_reports_the_sources_that_cannot_be_downloaded(): void
    {
        $builder = $this->createBuilder(new MockHttpClient([new MockResponse('Not Found', ['http_code' => 404])]), self::COMPOSER);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(sprintf('The sources of the commit could not be downloaded: HTTP 404 returned for "https://codeload.github.com/infection/infection/zip/%s".', self::SHA));

        $builder->build(new InfectionCommit(self::SHA));
    }

    public function test_it_reports_the_sources_that_cannot_be_extracted(): void
    {
        $builder = $this->createBuilder(new MockHttpClient([new MockResponse('not a zip', ['http_code' => 200])]), self::COMPOSER);

        $this->assertBuildFails($builder, 'The sources of the commit could not be extracted.');
    }

    public function test_it_reports_the_dependencies_that_cannot_be_installed(): void
    {
        $builder = $this->createBuilder(new MockHttpClient([new MockResponse($this->createArchive(), ['http_code' => 200])]), self::FAILING_COMPOSER);

        $this->assertBuildFails($builder, "The dependencies of the commit could not be installed: Your lock file does not contain a compatible set of packages.\n");
    }

    private function assertBuildFails(InfectionSourceBuilder $builder, string $expectedMessage): void
    {
        try {
            $builder->build(new InfectionCommit(self::SHA));

            self::fail('The build should fail.');
        } catch (RuntimeException $exception) {
            self::assertSame($expectedMessage, $exception->getMessage());
        }

        self::assertFalse($builder->isBuilt(self::SHA));
        // the temporary directory is gone, the next run builds again
        self::assertSame(['.', '..', 'build.lock'], scandir($this->buildsDir));
    }

    private function createBuilder(MockHttpClient $httpClient, string $composerScript, string $gitHubToken = ''): InfectionSourceBuilder
    {
        $composerBinary = $this->testDir . '/composer';
        $this->filesystem->dumpFile($composerBinary, $composerScript);
        $this->filesystem->chmod($composerBinary, 0o755);

        return new InfectionSourceBuilder($httpClient, $this->filesystem, $this->buildsDir, $composerBinary, $gitHubToken);
    }

    /**
     * The archive of a commit, as GitHub makes it.
     */
    private function createArchive(bool $withBinary = true): string
    {
        $archivePath = $this->testDir . '/source.zip';
        $this->filesystem->mkdir($this->testDir);

        $zip = new ZipArchive();
        $zip->open($archivePath, ZipArchive::CREATE);

        if ($withBinary) {
            $zip->addFromString('infection-' . self::SHA . '/bin/infection', '#!/usr/bin/env php');
        }

        $zip->addFromString('infection-' . self::SHA . '/composer.json', '{}');
        $zip->close();

        return (string) file_get_contents($archivePath);
    }

    private function createBuild(string $sha, int $lastUse): string
    {
        $buildDir = $this->buildsDir . '/' . $sha;
        $this->filesystem->dumpFile($buildDir . '/bin/infection', '');
        $this->filesystem->touch($buildDir, $lastUse);

        return $buildDir;
    }
}
