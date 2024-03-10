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

namespace App\Tests\Unit\Code\ClassesExtractor;

use App\Code\ClassesExtractor\ClassesExtractor;
use App\Code\ClassesExtractor\ClassesExtractorFactory;
use Generator;
use PHPUnit\Framework\TestCase;

final class ClassesExtractorTest extends TestCase
{
    private ClassesExtractor $extractor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->extractor = ClassesExtractorFactory::create();
    }

    /**
     * @dataProvider provideCodeWithClasses
     *
     * @param array<int, array{className: string, code: string}> $expectedClasses
     */
    public function test_it_extracts_classes_from_source_code(string $code, array $expectedClasses): void
    {
        $extractedClasses = $this->extractor->extract($code);

        foreach ($extractedClasses as $index => $extractedClass) {
            $this->assertSame($expectedClasses[$index]['className'], $extractedClass->getClassName());
            $this->assertSame($expectedClasses[$index]['code'], $extractedClass->getCode());
        }
    }

    public static function provideCodeWithClasses(): Generator
    {
        yield 'One class, simple class name' => [
            <<<'PHP'
<?php

declare(strict_types=1);

namespace Infected;

class ClassName
{

}
PHP
            ,
            [
                [
                    'className' => 'ClassName',
                    'code' => <<<'PHP'
<?php

declare(strict_types=1);

namespace Infected;

class ClassName
{
}
PHP
                ],
            ],
        ];

        yield 'One class, check it does not remove parenthesis' => [
            <<<'PHP'
<?php

declare(strict_types=1);

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
            [
                [
                    'className' => 'SourceClass',
                    'code' => <<<'PHP'
<?php

declare(strict_types=1);

namespace Infected;

class SourceClass
{
    public function getClassName() : string
    {
        return self::class;
    }
    public function newObject() : static
    {
        return new ($this->getClassName())();
    }
}
PHP
                ],
            ],
        ];

        yield 'One class, class name with numbers and underscores' => [
            <<<'PHP'
<?php

declare(strict_types=1);

namespace Infected;

class Class_Name_123
{

}
PHP
            ,
            [
                [
                    'className' => 'Class_Name_123',
                    'code' => <<<'PHP'
<?php

declare(strict_types=1);

namespace Infected;

class Class_Name_123
{
}
PHP
                ],
            ],
        ];

        yield 'Two classes, simple names' => [
            <<<'PHP'
<?php

declare(strict_types=1);

namespace Infected;

class Class1
{

}

class Class2
{

}
PHP
            ,
            [
                [
                    'className' => 'Class1',
                    'code' => <<<'PHP'
<?php

declare(strict_types=1);

namespace Infected;

class Class1
{
}
PHP
                ],
                [
                    'className' => 'Class2',
                    'code' => <<<'PHP'
<?php

declare(strict_types=1);

namespace Infected;

class Class2
{
}
PHP
                ],
            ],
        ];

        yield 'Two classes, one extends another' => [
            <<<'PHP'
<?php

declare(strict_types=1);

namespace Infected;

class Class1
{

}

class Class2 extends Class1
{

}
PHP
            ,
            [
                [
                    'className' => 'Class1',
                    'code' => <<<'PHP'
<?php

declare(strict_types=1);

namespace Infected;

class Class1
{
}
PHP
                ],
                [
                    'className' => 'Class2',
                    'code' => <<<'PHP'
<?php

declare(strict_types=1);

namespace Infected;

class Class2 extends Class1
{
}
PHP
                ],
            ],
        ];
    }
}
