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
