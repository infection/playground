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

namespace App\Validator;

use function array_map;
use function implode;
use function json_decode;
use const JSON_ERROR_NONE;
use function json_last_error;
use JsonSchema\Validator;
use const PHP_EOL;
use function sprintf;
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

        if ($value === null) {
            return;
        }

        $decodedJsonObject = json_decode($value);

        if (json_last_error() !== JSON_ERROR_NONE) {
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
