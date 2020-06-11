<?php

declare(strict_types=1);

namespace App\Validator;

use Symfony\Component\Validator\Constraint;

/**
 * @Annotation
 */
class ValidPhpCode extends Constraint
{
    /**
     * @var string
     */
    public $message = 'This is not a valid PHP code. Errors: {{ errors }}';
}
