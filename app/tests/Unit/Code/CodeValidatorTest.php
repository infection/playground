<?php

declare(strict_types=1);

namespace App\Tests\Unit\Code;

use App\Code\CodeValidator;
use function array_map;
use PhpParser\Error;
use PHPUnit\Framework\TestCase;

final class CodeValidatorTest extends TestCase
{
    /**
     * @var CodeValidator
     */
    private $codeValidator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->codeValidator = new CodeValidator();
    }

    /**
     * @dataProvider provideCodeExamples
     *
     * @param array<int, string> $expectedErrors
     */
    public function test_it_validates_php_code(string $phpCode, array $expectedErrors): void
    {
        $errors = $this->codeValidator->validate($phpCode);

        $actualErrorMessages = array_map(
            static function (Error $error): string {
                return $error->getMessage();
            },
            $errors
        );

        self::assertSame($expectedErrors, $actualErrorMessages);
    }

    public function provideCodeExamples(): \Generator
    {
        yield 'Valid code with no errors' => [
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
            ,
            [],
        ];

        yield 'Invalid function keyword' => [
            <<<'PHP'
<?php

namespace Xyz;

class Test
{
    public fun test()
    {
    
    }
}
PHP
            ,
            [
                'Syntax error, unexpected T_STRING, expecting T_VARIABLE on line 7',
            ],
        ];

        yield 'Null point exception' => [
            <<<'PHP'
<?php

namespace Xyz;

class Test
{
    public function test()
    {
        $obj%->get();
    }
}
PHP
            ,
            [
                'Syntax error, unexpected T_OBJECT_OPERATOR on line 9',
            ],
        ];

        yield 'Invalid parameters' => [
            <<<'PHP'
<?php

namespace Xyz;

class Test
{
    public function test($a => $b)
    {
        
    }
}
PHP
            ,
            [
                'Syntax error, unexpected T_DOUBLE_ARROW, expecting \')\' on line 7',
            ],
        ];

        yield 'Abstract final class' => [
            <<<'PHP'
<?php

namespace Xyz;

final abstract class Test
{
    public function test()
    {
    }
}
PHP
            ,
            [
                'Syntax error, unexpected T_ABSTRACT, expecting T_CLASS on line 5',
            ],
        ];

        yield 'Invalid math operation' => [
            <<<'PHP'
<?php

namespace Xyz;

final class Test
{
    public function test()
    {
        1 +*- 2;
    }
}
PHP
            ,
            [
                'Syntax error, unexpected \'*\' on line 9',
            ],
        ];

        yield 'No closing bracket in function' => [
            <<<'PHP'
<?php

namespace Xyz;

final class Test
{
    public function test()
    {
}
PHP
            ,
            [
                'Syntax error, unexpected EOF on line 9',
            ],
        ];
    }
}