<?php

declare(strict_types=1);

namespace App\Validator;

use App\Code\CodeValidator;
use function array_map;
use function implode;
use PhpParser\Error;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Webmozart\Assert\Assert;

class ValidPhpCodeValidator extends ConstraintValidator
{
    /**
     * @var CodeValidator
     */
    private $codeValidator;

    public function __construct(CodeValidator $codeValidator)
    {
        $this->codeValidator = $codeValidator;
    }

    /**
     * @param mixed $phpCode
     * @param Constraint $constraint
     */
    public function validate($phpCode, Constraint $constraint): void
    {
        Assert::isInstanceOf($constraint, ValidPhpCode::class);
        /* @var $constraint ValidPhpCode */

        if (null === $phpCode || '' === $phpCode) {
            return;
        }

        $errors = $this->codeValidator->validate($phpCode);

        if (\count($errors) > 0) {
            $errorMessages = array_map(
                static function (Error $error): string {
                    return $error->getMessage();
                },
                $errors
            );

            $this->context->buildViolation($constraint->message)
                ->setParameter('{{ errors }}', implode(' ', $errorMessages))
                ->addViolation();
        }
    }
}
