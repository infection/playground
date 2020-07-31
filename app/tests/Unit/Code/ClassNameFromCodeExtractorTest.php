<?php

declare(strict_types=1);

namespace App\Tests\Unit\Code;

use App\Code\ClassNameFromCodeExtractor;
use PHPUnit\Framework\TestCase;

final class ClassNameFromCodeExtractorTest extends TestCase
{
    private ClassNameFromCodeExtractor $extractor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->extractor = new ClassNameFromCodeExtractor();
    }

    /**
     * @dataProvider provideCodeWithClassNames
     */
    public function test_it_extracts_class_name_from_the_source_code(string $code, string $expectedClassName): void
    {
        $className = $this->extractor->extract($code);

        self::assertSame($expectedClassName, $className);
    }

    public function provideCodeWithClassNames(): \Generator
    {
        yield 'Simple ClassName. Bracket on the next line' => [
            <<<'PHP'
class ClassName
{

}
PHP
            ,
            'ClassName',
        ];

        yield 'Simple ClassName. Bracket after the empty line' => [
            <<<'PHP'
class ClassName
{

}
PHP
            ,
            'ClassName',
        ];

        yield 'Simple ClassName. Bracket on the same line' => [
            <<<'PHP'
class ClassName {

}
PHP
            ,
            'ClassName',
        ];

        yield 'Class name with numbers. Bracket on the same line' => [
            <<<'PHP'
class ClassName1234 {

}
PHP
            ,
            'ClassName1234',
        ];

        yield 'Class name with numbers and underscores. Bracket on the same line' => [
            <<<'PHP'
class Class_Name_1234 {

}
PHP
            ,
            'Class_Name_1234',
        ];

        yield 'Original full code' => [
            <<<'PHP'
<?php

declare(strict_types=1);

namespace Infected;

class SourceClassA
{
    public function add(int $a, int $b): int
    {
        return $a + $b;
    }
}
PHP
            ,
            'SourceClassA',
        ];
    }
}
