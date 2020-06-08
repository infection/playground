<?php

declare(strict_types=1);

namespace App\Tests\Unit\Code;

use App\Code\CodeSanitizer;
use PhpParser\Lexer\Emulative;
use PhpParser\NodeTraverser;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard;
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

        $lexer = new Emulative([
            'usedAttributes' => [
                'comments', 'startLine', 'endLine', 'startTokenPos', 'endTokenPos', 'startFilePos', 'endFilePos',
            ],
        ]);
        $parser = (new ParserFactory())->create(ParserFactory::PREFER_PHP7, $lexer);

        $this->codeSanitizer = new CodeSanitizer($parser, new Standard(), new NodeTraverser());
    }

    /**
     * @dataProvider provideCodeExamples
     */
    public function test_it_sanitizes_original_php_code(string $originalCode, string $expectedSanitizedCode): void
    {
        self::markTestSkipped('TODO');

        $sanitizedCode = $this->codeSanitizer->sanitize($originalCode);

        self::assertSame($expectedSanitizedCode, $sanitizedCode);
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
            ,
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

        // try it
        // https://github.com/phpstan/playground/blob/63ecba15fdbb8bb0bf0ed30b4a60ee954fc3389c/app/Model/CodeSanitizer.php
        // seems like this code removes all the code outside the class, which is quite good for infection as well

        // validate the code https://github.com/phpstan/playground/blob/63ecba15fdbb8bb0bf0ed30b4a60ee954fc3389c/app/Model/CodeValidator.php
    }
}
