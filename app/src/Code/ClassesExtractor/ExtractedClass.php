<?php

declare(strict_types=1);

namespace App\Code\ClassesExtractor;

final class ExtractedClass
{
    private string $className;

    private string $code;

    public function __construct(string $className, string $code)
    {
        $this->className = $className;
        $this->code = $code;
    }

    public function getClassName(): string
    {
        return $this->className;
    }

    public function getCode(): string
    {
        return $this->code;
    }
}
