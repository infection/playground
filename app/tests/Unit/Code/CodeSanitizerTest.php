<?php

declare(strict_types=1);

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
        $sanitizedCode = $this->codeSanitizer->sanitize($originalCode);

        $this->assertSame($expectedSanitizedCode, $sanitizedCode);
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
    }
}