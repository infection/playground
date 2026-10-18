<?php
/**
 * This code is licensed under the BSD 3-Clause License.
 *
 * Copyright (c) 2017, Maks Rafalko
 * All rights reserved.
 *
 * Redistribution and use in source and binary forms, with or without
 * modification, are permitted provided that the following conditions are met:
 *
 * * Redistributions of source code must retain the above copyright notice, this
 *   list of conditions and the following disclaimer.
 *
 * * Redistributions in binary form must reproduce the above copyright notice,
 *   this list of conditions and the following disclaimer in the documentation
 *   and/or other materials provided with the distribution.
 *
 * * Neither the name of the copyright holder nor the names of its
 *   contributors may be used to endorse or promote products derived from
 *   this software without specific prior written permission.
 *
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS"
 * AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE
 * IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE ARE
 * DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT HOLDER OR CONTRIBUTORS BE LIABLE
 * FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL
 * DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR
 * SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER
 * CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY,
 * OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE
 * OF THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
 */

declare(strict_types=1);

namespace App\Code\ClassesExtractor;

use App\Code\Visitor\ExtractClassesVisitor;
use function count;
use function min;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\Parser;
use PhpParser\PrettyPrinter\Standard;
use function substr;
use Webmozart\Assert\Assert;

final class ClassesExtractor
{
    private Parser $parser;

    private NodeTraverser $traverser;

    /**
     * Prints the classes instead of copying their source: the class files of the runs made before
     * the source was copied were printed, their lines are mapped by printing the classes again
     */
    private ?Standard $prettyPrinter;

    private ExtractClassesVisitor $extractClassesVisitor;

    public function __construct(Parser $parser, NodeTraverser $traverser, ?Standard $prettyPrinter, ExtractClassesVisitor $extractClassesVisitor)
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

        // FrankenPHP's worker mode keeps this service between requests: the visitor must not
        // keep the classes of one run for the next one, even when this run fails
        try {
            $this->traverser->traverse($initialStmts);

            $extractedClasses = [];

            foreach ($this->extractClassesVisitor->getClassNodes() as $classNode) {
                Assert::notNull($classNode->name);

                $classCode = $this->buildClassCode($code, $classNode);

                $extractedClasses[] = new ExtractedClass(
                    $classNode->name->toString(),
                    $classCode,
                    $this->buildLineMap($classNode, $classCode),
                );
            }

            return $extractedClasses;
        } finally {
            $this->extractClassesVisitor->clear();
        }
    }

    private function buildClassCode(string $code, Node\Stmt\ClassLike $classNode): string
    {
        $classSource = $this->prettyPrinter === null
            ? self::copyClassSource($code, $classNode)
            : $this->prettyPrinter->prettyPrint([$classNode]);

        return <<<"PHP"
<?php

declare(strict_types=1);

namespace Infected;

{$classSource}
PHP;
    }

    /**
     * The class is copied as it was submitted, with its doc comment: Infection shows the mutants on the code the user wrote.
     */
    private static function copyClassSource(string $code, Node\Stmt\ClassLike $classNode): string
    {
        $startPosition = $classNode->getStartFilePos();

        foreach ($classNode->getComments() as $comment) {
            $startPosition = min($startPosition, $comment->getStartFilePos());
        }

        return substr($code, $startPosition, $classNode->getEndFilePos() - $startPosition + 1);
    }

    /**
     * The class file has a header of its own, and the class is pretty-printed for the runs made before it was copied,
     * so Infection reports lines of a file the user never sees.
     * Both trees have the same nodes, so they are paired in traversal order.
     *
     * @return array<int, int> line in the extracted file => line in the submitted code
     */
    private function buildLineMap(Node\Stmt\ClassLike $originalClassNode, string $classCode): array
    {
        $nodeFinder = new NodeFinder();

        /** @var Node\Stmt[] $extractedStmts */
        $extractedStmts = $this->parser->parse($classCode);

        $extractedClassNode = $nodeFinder->findFirstInstanceOf($extractedStmts, Node\Stmt\ClassLike::class);

        if ($extractedClassNode === null) {
            return [];
        }

        $originalNodes = $nodeFinder->find([$originalClassNode], static fn (): bool => true);
        $extractedNodes = $nodeFinder->find([$extractedClassNode], static fn (): bool => true);

        if (count($originalNodes) !== count($extractedNodes)) {
            return [];
        }

        $lineMap = [];

        foreach ($extractedNodes as $index => $extractedNode) {
            // `else { if }` is printed as `else if`: the line is the one of the `if`, there is nothing to mutate in the `else`
            if ($extractedNode instanceof Node\Stmt\Else_) {
                continue;
            }

            $lineMap[$extractedNode->getStartLine()] ??= $originalNodes[$index]->getStartLine();
        }

        return $lineMap;
    }
}
