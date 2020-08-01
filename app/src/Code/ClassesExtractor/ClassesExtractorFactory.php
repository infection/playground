<?php

declare(strict_types=1);

namespace App\Code\ClassesExtractor;

use App\Code\Visitor\ExtractClassesVisitor;
use PhpParser\Lexer\Emulative;
use PhpParser\NodeTraverser;
use PhpParser\Parser\Php7;
use PhpParser\PrettyPrinter\Standard;

final class ClassesExtractorFactory
{
    public static function create(): ClassesExtractor
    {
        $lexer = new Emulative([
            'usedAttributes' => [
                'comments',
                'startLine', 'endLine',
                'startTokenPos', 'endTokenPos',
            ],
        ]);
        $parser = new Php7($lexer);

        $traverser = new NodeTraverser();

        $extractClassesVisitor = new ExtractClassesVisitor();

        $traverser->addVisitor($extractClassesVisitor);

        $prettyPrinter = new Standard();

        return new ClassesExtractor($parser, $traverser, $prettyPrinter, $extractClassesVisitor);
    }
}
