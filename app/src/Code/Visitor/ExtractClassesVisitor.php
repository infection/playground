<?php

declare(strict_types=1);

namespace App\Code\Visitor;

use PhpParser\Node;
use PhpParser\NodeVisitorAbstract;

final class ExtractClassesVisitor extends NodeVisitorAbstract
{
    /**
     * @var array<int, Node\Stmt\ClassLike>
     */
    private array $classNodes = [];

    public function enterNode(Node $node)
    {
        if ($node instanceof Node\Stmt\ClassLike) {
            $this->classNodes[] = $node;
        }

        return null;
    }

    /**
     * @return array<int, Node\Stmt\ClassLike>
     */
    public function getClassNodes(): array
    {
        return $this->classNodes;
    }

    public function clear(): void
    {
        $this->classNodes = [];
    }
}
