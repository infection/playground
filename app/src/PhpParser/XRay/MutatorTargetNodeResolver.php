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

namespace App\PhpParser\XRay;

use PhpParser\Node;
use PhpParser\Node\Attribute;
use PhpParser\Node\AttributeGroup;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Param;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\UseUse;

/**
 * Resolves the node class that should be used in Mutator::canMutate() to mutate the given node.
 *
 * Same logic as AstController::resolveTargetNodeClass(), but tolerant to nodes without a parent.
 * Requires the "parent" attribute set by ParentConnectingVisitor.
 */
final class MutatorTargetNodeResolver
{
    public function resolve(Node $node): string
    {
        $parentNode = $node->getAttribute('parent');

        if ($node instanceof UseUse || $node instanceof AttributeGroup) {
            return $parentNode instanceof Node ? $parentNode::class : $node::class;
        }

        if ($node instanceof Attribute) {
            $stmt = $parentNode instanceof Node ? $parentNode->getAttribute('parent') : null;

            return $stmt instanceof Node ? $stmt::class : $node::class;
        }

        if ($node instanceof Stmt) {
            return $node::class;
        }

        // special case
        if ($node instanceof Variable && $parentNode instanceof Param) {
            return $parentNode::class;
        }

        // target one level up
        if (($node instanceof Identifier || $node instanceof Name || $node instanceof Variable) && $parentNode instanceof Node) {
            return $this->resolve($parentNode);
        }

        return $node::class;
    }
}
