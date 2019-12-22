<?php

declare(strict_types=1);

namespace App\Code\Visitor;

use PhpParser\NodeVisitorAbstract;
use PhpParser\Node;

final class ReplaceNamespaceVisitor extends NodeVisitorAbstract
{
    /**
     * @var string
     */
    private $desiredNamespaceName;

    public function __construct(string $desiredNamespaceName)
    {
        $this->desiredNamespaceName = $desiredNamespaceName;
    }

    public function enterNode(Node $node)
    {
        if ($node instanceof Node\Stmt\Namespace_) {
            $node->name = new Node\Name($this->desiredNamespaceName);

            return $node;
        }

        return null;
    }
}
