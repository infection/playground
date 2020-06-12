<?php

declare(strict_types=1);

namespace App\Code;

use App\Code\Visitor\ReplaceNamespaceVisitor;
use PhpParser\Lexer\Emulative;
use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\CloningVisitor;
use PhpParser\Parser;
use PhpParser\PrettyPrinter\Standard;

final class CodeSanitizer
{
    private Parser $parser;
    private Standard $prettyPrinter;
    private NodeTraverser $nodeTraverser;

    /**
     * @var Emulative
     */
    private $lexer;

    public function __construct(Parser $parser, Standard $prettyPrinter, NodeTraverser $nodeTraverser, Emulative $lexer)
    {
        $this->parser = $parser;
        $this->prettyPrinter = $prettyPrinter;
        $this->nodeTraverser = $nodeTraverser;
        $this->lexer = $lexer;
    }

    public function sanitize(string $originalPhpCode): string
    {
        /** @var Node\Stmt[] $initialStmts */
        $initialStmts = $this->parser->parse($originalPhpCode);

        $cloningNodeTraverser = new NodeTraverser();
        $cloningNodeTraverser->addVisitor(new CloningVisitor());

        $newStmts = $cloningNodeTraverser->traverse($initialStmts);

        $newStmts = $this->replaceNamespace($newStmts);

        return $this->prettyPrinter->printFormatPreserving($newStmts, $initialStmts, $this->lexer->getTokens());
    }

    /**
     * @param Node[] $initialNodes
     *
     * @return Node[]
     */
    private function replaceNamespace(array $initialNodes): array
    {
        $this->nodeTraverser->addVisitor(new ReplaceNamespaceVisitor('Infected'));

        return $this->nodeTraverser->traverse($initialNodes);
    }
}
