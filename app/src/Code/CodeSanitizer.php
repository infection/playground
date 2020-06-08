<?php

declare(strict_types=1);

namespace App\Code;

use App\Code\Visitor\ReplaceNamespaceVisitor;
use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\Parser;
use PhpParser\PrettyPrinter\Standard;
use Webmozart\Assert\Assert;

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
        /** @var Node\Stmt[] $initialNodes */
        $initialNodes = $this->parser->parse($originalPhpCode);

        Assert::notNull($initialNodes);

        $filteredNodes = $this->filterNodes($initialNodes);

        return $this->prettyPrinter->prettyPrintFile($filteredNodes);
    }

    /**
     * @param Node\Stmt[] $initialNodes
     *
     * @return Node[]
     */
    private function filterNodes(array $initialNodes): array
    {
        $this->nodeTraverser->addVisitor(new ReplaceNamespaceVisitor('Infected'));

        return $this->nodeTraverser->traverse($initialNodes);
    }
}
