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
use function file_get_contents;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use function scandir;
use function sprintf;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use function sys_get_temp_dir;
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

        self::assertSame('https://codeload.github.com/infection/infection/zip/' . self::SHA, $archiveResponse->getRequestUrl());
        self::assertSame(30.0, $archiveResponse->getRequestOptions()['timeout']);

        // the temporary directory is gone
        self::assertSame(['.', '..', self::SHA, self::SHA . '.lock'], scandir($this->buildsDir));
    }

    public function test_a_commit_is_built_once(): void
    {
        $this->filesystem->dumpFile($this->buildsDir . '/' . self::SHA . '/bin/infection', '');
        $httpClient = new MockHttpClient([]);

        $builder = $this->createBuilder($httpClient, self::FAILING_COMPOSER);

        self::assertTrue($builder->isBuilt(self::SHA));
        self::assertSame($this->buildsDir . '/' . self::SHA . '/bin/infection', $builder->build(new InfectionCommit(self::SHA)));
        self::assertSame(0, $httpClient->getRequestsCount());
        // without waiting for the lock of the build
        self::assertSame(['.', '..', self::SHA], scandir($this->buildsDir));
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
        self::assertSame(['.', '..', self::SHA . '.lock'], scandir($this->buildsDir));
    }

    private function createBuilder(MockHttpClient $httpClient, string $composerScript): InfectionSourceBuilder
    {
        $composerBinary = $this->testDir . '/composer';
        $this->filesystem->dumpFile($composerBinary, $composerScript);
        $this->filesystem->chmod($composerBinary, 0o755);

        return new InfectionSourceBuilder($httpClient, $this->filesystem, $this->buildsDir, $composerBinary);
    }

    /**
     * The archive of a commit, as GitHub makes it.
     */
    private function createArchive(): string
    {
        $archivePath = $this->testDir . '/source.zip';
        $this->filesystem->mkdir($this->testDir);

        $zip = new ZipArchive();
        $zip->open($archivePath, ZipArchive::CREATE);
        $zip->addFromString('infection-' . self::SHA . '/bin/infection', '#!/usr/bin/env php');
        $zip->addFromString('infection-' . self::SHA . '/composer.json', '{}');
        $zip->close();

        return (string) file_get_contents($archivePath);
    }
}
