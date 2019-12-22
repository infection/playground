<?php

declare(strict_types=1);

namespace App\Code;

use App\Code\Visitor\ReplaceNamespaceVisitor;
use Infection\Visitor\CloneVisitor;
use Infection\Visitor\MutatorVisitor;
use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\Parser;
use PhpParser\PrettyPrinter\Standard;

final class CodeSanitizer
{
    /**
     * @var Parser
     */
    private $parser;

    /**
     * @var Standard
     */
    private $prettyPrinter;
    /**
     * @var NodeTraverser
     */
    private $nodeTraverser;

    public function __construct(Parser $parser, Standard $prettyPrinter, NodeTraverser $nodeTraverser)
    {
        $this->parser = $parser;
        $this->prettyPrinter = $prettyPrinter;
        $this->nodeTraverser = $nodeTraverser;
    }

    public function sanitize(string $originalPhpCode): string
    {
        $initialNodes = $this->parser->parse($originalPhpCode);
        $filteredNodes = $this->filterNodes($initialNodes);

        return $this->prettyPrinter->prettyPrintFile($filteredNodes);
    }

    /**
     * @param Node[] $initialNodes
     * @return Node[]
     */
    private function filterNodes(array $initialNodes): array
    {
        $this->nodeTraverser->addVisitor(new ReplaceNamespaceVisitor('Infected'));

        return $this->nodeTraverser->traverse($initialNodes);
    }
}
