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

namespace App\Tests\Unit\AutoReview;

use App\Infection\Runner;
use App\Infection\StaticAnalysisTool;
use function file_get_contents;
use PHPUnit\Framework\TestCase;
use function sprintf;
use function substr_count;

/**
 * The top bar and the stored runs show Runner::CURRENT_PHPSTAN_VERSION: it must be the version the Dockerfile installs.
 */
final class PhpStanVersionTest extends TestCase
{
    public function test_the_dockerfile_installs_the_current_phpstan_version(): void
    {
        $dockerfile = (string) file_get_contents(__DIR__ . '/../../../Dockerfile');

        // one PHAR for all architectures
        self::assertSame(1, substr_count($dockerfile, sprintf('/phpstan/releases/download/%s/phpstan.phar', Runner::CURRENT_PHPSTAN_VERSION)));
        self::assertSame(1, substr_count($dockerfile, '/phpstan/releases/download/'));
    }

    public function test_phpstan_reports_the_current_version(): void
    {
        self::assertSame('PHPStan', StaticAnalysisTool::PHPStan->getLabel());
        self::assertSame(Runner::CURRENT_PHPSTAN_VERSION, StaticAnalysisTool::PHPStan->getCurrentVersion());
    }
}
