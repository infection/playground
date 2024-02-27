<?php

declare(strict_types=1);

namespace App\Tests\Unit\Code\ClassesExtractor;

use App\Code\ClassesExtractor\ClassesExtractor;
use App\Code\ClassesExtractor\ClassesExtractorFactory;
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
            self::assertSame($expectedClasses[$index]['className'], $extractedClass->getClassName());
            self::assertSame($expectedClasses[$index]['code'], $extractedClass->getCode());
        }
    }

    public function provideCodeWithClasses(): \Generator
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
