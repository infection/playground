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

namespace App\Tests\Unit\Explore;

use App\Explore\RunSubject;
use Generator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RunSubjectTest extends TestCase
{
    #[DataProvider('provideCode')]
    public function test_it_names_a_run_by_its_first_class_and_method(string $code, ?string $expected): void
    {
        self::assertSame($expected, (new RunSubject())->describe($code));
    }

    public static function provideCode(): Generator
    {
        yield 'class and methods' => [
            '<?php namespace Infected; final class Money { private int $cents = 0; public function add(): void {} public function sub(): void {} }',
            'Money::add()',
        ];

        yield 'first of several classes' => [
            '<?php class Price { public function total(): int { return 1; } } class Cart { public function add(): void {} }',
            'Price::total()',
        ];

        // yield 'first class without methods' => [
        //     '<?php class Empty_ {} class Other { public function run(): void {} }',
        //     'Empty_',
        // ];

        // yield 'enum' => ['<?php enum Suit { case Hearts; }', 'Suit'];

        // yield 'anonymous class only' => ['<?php $a = new class { public function run(): void {} };', null];

        // yield 'no class' => ['<?php function add(): int { return 1; }', null];

        // yield 'invalid code' => ['<?php class {', null];
    }
}
