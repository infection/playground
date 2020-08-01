<?php

declare(strict_types=1);

namespace App\Code\ClassesExtractor;

use App\Code\Visitor\ExtractClassesVisitor;
use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\Parser;
use PhpParser\PrettyPrinter\Standard;
use Webmozart\Assert\Assert;

final class ClassesExtractor
{
    private Parser $parser;

    private NodeTraverser $traverser;

    private Standard $prettyPrinter;

    private ExtractClassesVisitor $extractClassesVisitor;

    public function __construct(Parser $parser, NodeTraverser $traverser, Standard $prettyPrinter, ExtractClassesVisitor $extractClassesVisitor)
    {
        $this->parser = $parser;
        $this->traverser = $traverser;
        $this->prettyPrinter = $prettyPrinter;
        $this->extractClassesVisitor = $extractClassesVisitor;
    }

    /**
     * @return array<int, ExtractedClass>
     */
    public function extract(string $code): array
    {
        /** @var Node\Stmt[] $initialStmts */
        $initialStmts = $this->parser->parse($code);

        $this->traverser->traverse($initialStmts);

        $extractedClasses = [];

        foreach ($this->extractClassesVisitor->getClassNodes() as $classNode) {
            Assert::notNull($classNode->name);

            $extractedClasses[] = new ExtractedClass(
                $classNode->name->toString(),
                $this->buildClassCode($classNode)
            );
        }

        $this->extractClassesVisitor->clear();

        return $extractedClasses;
    }

    private function buildClassCode(Node\Stmt\ClassLike $classNode): string
    {
        return <<<"PHP"
<?php

declare(strict_types=1);

namespace Infected;

{$this->prettyPrinter->prettyPrint([$classNode])}
PHP;
    }
}
