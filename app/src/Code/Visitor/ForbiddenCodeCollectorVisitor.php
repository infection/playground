<?php

declare(strict_types=1);

namespace App\Code\Visitor;

use App\Code\Validator\CodeValidator;
use App\Code\Validator\Error;
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

        if (\in_array($node->name->toLowerString(), CodeValidator::FORBIDDEN_FUNCTIONS, true)) {
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
