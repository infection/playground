<?php

declare(strict_types=1);

namespace App\Validator;

use function array_map;
use function implode;
use function json_decode;
use const JSON_ERROR_NONE;
use function json_last_error;
use JsonSchema\Validator;
use const PHP_EOL;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

class JsonSchemaValidator extends ConstraintValidator
{
    private string $projectDir;

    public function __construct(string $projectDir)
    {
        $this->projectDir = $projectDir;
    }

    public function validate($value, Constraint $constraint): void
    {
        /* @var $constraint \App\Validator\JsonSchema */

        if (null === $value) {
            return;
        }

        $decodedJsonObject = json_decode($value);

        if (JSON_ERROR_NONE !== json_last_error()) {
            $this->context->buildViolation('This value should be valid JSON.')->addViolation();

            return;
        }

        $validator = new Validator();
        $validator->validate($decodedJsonObject, (object) [
            '$ref' => sprintf('file://%s/src/Json/%s', $this->projectDir, $constraint->schemaFile),
        ]);

        if ($validator->isValid()) {
            return;
        }

        $errors = array_map(
            static function (array $error): string {
                return sprintf('[%s] %s%s', $error['property'], $error['message'], PHP_EOL);
            },
            $validator->getErrors()
        );

        $this->context->buildViolation(implode(PHP_EOL, $errors))->addViolation();
    }
}
