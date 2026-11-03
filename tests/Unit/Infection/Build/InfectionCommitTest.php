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
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class InfectionCommitTest extends TestCase
{
    private const SHA = 'dbc3013fc473271b4bd86f5ec103702a19d86e83';

    public function test_a_commit_of_a_pull_request(): void
    {
        $commit = new InfectionCommit(self::SHA, 3717);

        self::assertSame('dbc3013', $commit->getShortSha());
        self::assertSame('#3717 dbc3013', $commit->getLabel());
        self::assertSame('https://github.com/infection/infection/pull/3717/commits/' . self::SHA, $commit->getUrl());
    }

    public function test_a_commit_of_the_default_branch(): void
    {
        $commit = new InfectionCommit(self::SHA);

        self::assertSame('dbc3013', $commit->getLabel());
        self::assertSame('https://github.com/infection/infection/commit/' . self::SHA, $commit->getUrl());
    }

    public function test_it_tells_a_commit_from_a_released_version(): void
    {
        self::assertTrue(InfectionCommit::isSha(self::SHA));
        self::assertFalse(InfectionCommit::isSha('0.35.6'));
        self::assertFalse(InfectionCommit::isSha('dbc3013'));
        self::assertFalse(InfectionCommit::isSha(null));
    }

    public function test_it_needs_the_full_sha(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Expected a full commit SHA, got "dbc3013".');

        new InfectionCommit('dbc3013');
    }

    public function test_a_pull_request_has_a_positive_number(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Expected a positive integer. Got: 0');

        new InfectionCommit(self::SHA, 0);
    }
}
