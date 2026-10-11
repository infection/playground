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

namespace App\Code\Visitor;

use App\Code\Validator\CodeValidator;
use App\Code\Validator\Error;
use function in_array;
use PhpParser\Node;
use PhpParser\NodeVisitorAbstract;

final class ForbiddenCodeCollectorVisitor extends NodeVisitorAbstract
{
    private const INCLUDE_TYPE_MAP = [
        Node\Expr\Include_::TYPE_INCLUDE => 'include',
        Node\Expr\Include_::TYPE_INCLUDE_ONCE => 'include_once',
        Node\Expr\Include_::TYPE_REQUIRE => 'require',
        Node\Expr\Include_::TYPE_REQUIRE_ONCE => 'require_once',
    ];

    /**
     * @var Error[]
     */
    private array $errors = [];

    public function leaveNode(Node $node)
    {
        if ($node instanceof Node\Stmt\Expression) {
            if ($node->expr instanceof Node\Expr\Eval_) {
                $this->errors[] = Error::forbiddenFunction('eval', $node->getLine());

                return null;
            }

            if ($node->expr instanceof Node\Expr\Include_) {
                $this->errors[] = Error::forbiddenFunction(self::INCLUDE_TYPE_MAP[$node->expr->type], $node->getLine());

                return null;
            }

            if ($node->expr instanceof Node\Expr\ShellExec) {
                $this->errors[] = Error::forbiddenBackticks($node->getLine());

                return null;
            }
        }

        if (!$node instanceof Node\Expr\FuncCall || !$node->name instanceof Node\Name) {
            return null;
        }

        $lowerCasedFunction = $node->name->toLowerString();

        if (in_array($node->name->toLowerString(), CodeValidator::FORBIDDEN_FUNCTIONS, true)) {
            $this->errors[] = Error::forbiddenFunction($lowerCasedFunction, $node->getLine());
        }

        return null;
    }

    /**
     * @return Error[]
     */
    public function getErrors(): array
    {
        return $this->errors;
    }
}
