<?php

declare(strict_types=1);

namespace App\Code;

use PhpParser\Lexer\Emulative;
use PhpParser\NodeTraverser;
use PhpParser\Parser\Php7;
use PhpParser\PrettyPrinter\Standard;

final class CodeSanitizerFactory
{
    public static function create(): CodeSanitizer
    {
        $lexer = new Emulative([
            'usedAttributes' => [
                'comments',
                'startLine', 'endLine',
                'startTokenPos', 'endTokenPos',
            ],
        ]);
        $parser = new Php7($lexer);

        return new CodeSanitizer($parser, new Standard(), new NodeTraverser(), $lexer);
    }
}
