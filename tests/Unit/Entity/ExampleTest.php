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

namespace App\Tests\Unit\Entity;

use App\Entity\Example;
use App\Infection\StaticAnalysisTool;
use InvalidArgumentException;
use function md5;
use PHPUnit\Framework\TestCase;

final class ExampleTest extends TestCase
{
    public function test_a_run_without_static_analysis_keeps_its_hash(): void
    {
        self::assertSame(
            md5('codetest{}0.35.613.4.18.5.0'),
            Example::hashInput('code', 'test', '{}', '0.35.6', '13.4.1', '8.5.0'),
        );
    }

    public function test_static_analysis_and_its_version_make_another_run(): void
    {
        self::assertSame(
            md5('codetest{}0.35.613.4.18.5.0mago1.56.3'),
            Example::hashInput('code', 'test', '{}', '0.35.6', '13.4.1', '8.5.0', StaticAnalysisTool::Mago, '1.56.3'),
        );
    }

    public function test_it_records_the_static_analysis_of_the_run(): void
    {
        $example = new Example('code', 'test', '{}', '0.35.6', '13.4.1', '8.5.0', StaticAnalysisTool::Mago, '1.56.3');

        self::assertSame(StaticAnalysisTool::Mago, $example->getStaticAnalysisTool());
        self::assertSame('1.56.3', $example->getStaticAnalysisToolVersion());
        self::assertSame(
            Example::hashInput('code', 'test', '{}', '0.35.6', '13.4.1', '8.5.0', StaticAnalysisTool::Mago, '1.56.3'),
            $example->getInputHash(),
        );
    }

    public function test_a_static_analysis_tool_needs_its_version(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('A static analysis tool and its version go together.');

        new Example('code', 'test', '{}', '0.35.6', '13.4.1', '8.5.0', StaticAnalysisTool::Mago);
    }

    public function test_a_version_needs_its_static_analysis_tool(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('A static analysis tool and its version go together.');

        new Example('code', 'test', '{}', '0.35.6', '13.4.1', '8.5.0', null, '1.56.3');
    }
}
