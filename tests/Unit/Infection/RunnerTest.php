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

use App\Code\ClassesExtractor\ClassesExtractorFactory;
use App\Infection\Build\InfectionCommit;
use App\Infection\Build\InfectionSourceBuilder;
use App\Infection\Runner;
use App\Infection\StaticAnalysisTool;
use Generator;
use function ini_get;
use function ini_set;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use function set_time_limit;
use Symfony\Component\Filesystem\Filesystem;

final class RunnerTest extends TestCase
{
    private const ID_HASH = '_runner-test';

    private const SHA = 'dbc3013fc473271b4bd86f5ec103702a19d86e83';

    protected function tearDown(): void
    {
        (new Filesystem())->remove(__DIR__ . '/../../../infection-builds/' . self::ID_HASH);
    }

    public function test_it_reports_a_commit_of_infection_that_cannot_be_built(): void
    {
        $sourceBuilder = self::createStub(InfectionSourceBuilder::class);
        $sourceBuilder->method('build')->willThrowException(new RuntimeException('The sources of the commit could not be downloaded.'));

        $result = $this->createRunner($sourceBuilder)->run(self::ID_HASH, '<?php class SourceClass {}', '<?php', '{}', null, new InfectionCommit(self::SHA));

        self::assertSame(
            'Infection could not be built from commit dbc3013fc473271b4bd86f5ec103702a19d86e83. The sources of the commit could not be downloaded.',
            $result->getAnsiOutput(),
        );
        self::assertNull($result->getJsonLog());
    }

    public function test_the_build_of_a_commit_outlives_the_request_time_limit(): void
    {
        $timeLimitDuringBuild = null;

        $sourceBuilder = self::createStub(InfectionSourceBuilder::class);
        $sourceBuilder->method('build')->willReturnCallback(static function () use (&$timeLimitDuringBuild): never {
            $timeLimitDuringBuild = (int) ini_get('max_execution_time');

            throw new RuntimeException('Stop before Infection runs.');
        });

        // the web server has a time limit, the CLI does not
        ini_set('max_execution_time', '30');

        try {
            $this->createRunner($sourceBuilder)->run(self::ID_HASH, '<?php class SourceClass {}', '<?php', '{}', null, new InfectionCommit(self::SHA));
        } finally {
            set_time_limit(0);
        }

        self::assertGreaterThan(InfectionSourceBuilder::BUILD_TIMEOUT_SEC, $timeLimitDuringBuild);
    }

    #[DataProvider('provideProcessTimeouts')]
    public function test_it_gives_the_infection_process_a_timeout(?StaticAnalysisTool $staticAnalysisTool, int $expectedTimeout): void
    {
        self::assertSame($expectedTimeout, Runner::getProcessTimeoutSec($staticAnalysisTool));
    }

    /**
     * @return Generator<string, array{?StaticAnalysisTool, int}>
     */
    public static function provideProcessTimeouts(): Generator
    {
        yield 'without static analysis' => [null, 30];

        yield 'Mago' => [StaticAnalysisTool::Mago, 30];

        yield 'PHPStan runs once per escaped mutant' => [StaticAnalysisTool::PHPStan, 90];
    }

    private function createRunner(InfectionSourceBuilder $sourceBuilder): Runner
    {
        return new Runner(new Filesystem(), ClassesExtractorFactory::create(), $sourceBuilder);
    }
}
