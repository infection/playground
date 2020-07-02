<?php

declare(strict_types=1);

namespace App\Validator;

use Symfony\Component\Validator\Constraint;

/**
 * @Annotation
 */
class JsonSchema extends Constraint
{
    /**
     * @var string
     */
    public $message = 'The value "{{ value }}" is not valid JSON.';

    /**
     * @var string
     */
    public $schemaFile;
}
