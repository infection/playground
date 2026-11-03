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

use App\Infection\RequestTimeLimit;
use function ini_get;
use function ini_set;
use PHPUnit\Framework\TestCase;
use function set_time_limit;

final class RequestTimeLimitTest extends TestCase
{
    private string $originalTimeLimit;

    protected function setUp(): void
    {
        $this->originalTimeLimit = ini_get('max_execution_time');
    }

    protected function tearDown(): void
    {
        set_time_limit((int) $this->originalTimeLimit);
    }

    public function test_it_restarts_the_time_limit_of_the_request_with_room_for_what_it_waits_for(): void
    {
        // the web server has a time limit
        ini_set('max_execution_time', '30');

        RequestTimeLimit::extend(100);

        // and what the request does after it: saving the result and redirecting
        self::assertSame('130', ini_get('max_execution_time'));
    }

    public function test_it_sets_no_time_limit_where_there_is_none(): void
    {
        // the CLI: a limit would end the process later
        ini_set('max_execution_time', '0');

        RequestTimeLimit::extend(100);

        self::assertSame('0', ini_get('max_execution_time'));
    }
}
