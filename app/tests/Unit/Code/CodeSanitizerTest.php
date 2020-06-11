<?php

declare(strict_types=1);

namespace App\Tests\Unit\Code;

use App\Code\CodeSanitizer;
use App\Code\CodeSanitizerFactory;
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
            self::assertSame($sanitizedCode, $sanitizedCode);
        } else {
            self::assertSame($expectedSanitizedCode, $sanitizedCode);
        }
    }

    public function provideCodeExamples(): \Generator
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

        // todo https://stackoverflow.com/questions/3115559/exploitable-php-functions

        // validate the code https://github.com/phpstan/playground/blob/63ecba15fdbb8bb0bf0ed30b4a60ee954fc3389c/app/Model/CodeValidator.php
    }
}
