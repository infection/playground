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

namespace App\Tests\Unit\Code;

use App\Code\CodeSanitizer;
use App\Code\CodeSanitizerFactory;
use Generator;
use PHPUnit\Framework\TestCase;

final class CodeSanitizerTest extends TestCase
{
    /**
     * @var CodeSanitizer
     */
    private $codeSanitizer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->codeSanitizer = CodeSanitizerFactory::create();
    }

    /**
     * @dataProvider provideCodeExamples
     */
    public function test_it_sanitizes_original_php_code(string $originalCode, ?string $expectedSanitizedCode = null): void
    {
        $sanitizedCode = $this->codeSanitizer->sanitize($originalCode);

        if ($expectedSanitizedCode === null) {
            $this->assertSame($sanitizedCode, $sanitizedCode);
        } else {
            $this->assertSame($expectedSanitizedCode, $sanitizedCode);
        }
    }

    public static function provideCodeExamples(): Generator
    {
        yield 'Do nothing with good valid code' => [
            <<<'PHP'
<?php

namespace Infected;

class Test
{
    public function add(int $a, int $b) : int
    {
        return $a + $b;
    }
}
PHP
        ];

        yield 'Replaces any namespace name to Infected' => [
            <<<'PHP'
<?php

namespace Xyz;

class Test
{
}
PHP
            ,
            <<<'PHP'
<?php

namespace Infected;

class Test
{
}
PHP
        ];

        yield 'Does not remove needed' => [
            <<<'PHP'
<?php

namespace Infected;

class SourceClass
{
    public function getClassName(): string
    {
        return self::class;
    }

    public function newObject(): static
    {
        return new ($this->getClassName())();
    }
}
PHP
            ,
            <<<'PHP'
<?php

namespace Infected;

class SourceClass
{
    public function getClassName(): string
    {
        return self::class;
    }

    public function newObject(): static
    {
        return new ($this->getClassName())();
    }
}
PHP
        ];

        // todo https://stackoverflow.com/questions/3115559/exploitable-php-functions

        // validate the code https://github.com/phpstan/playground/blob/63ecba15fdbb8bb0bf0ed30b4a60ee954fc3389c/app/Model/CodeValidator.php
    }
}
