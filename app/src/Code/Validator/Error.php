<?php

declare(strict_types=1);

namespace App\Code\Validator;

use function sprintf;

final class Error
{
    private string $message;
    private int $line;

    private function __construct(string $message, int $line)
    {
        $this->message = $message;
        $this->line = $line;
    }

    public static function forbiddenFunction(string $function, int $line): self
    {
        $errorMessage = sprintf('Function "%s" on line %d is not allowed to be used in Playground.', $function, $line);

        return new self($errorMessage, $line);
    }

    public static function forbiddenBackticks(int $line): self
    {
        return new Error('Using Backticks ("``") is not allowed in Playground.', $line);
    }

    public static function inSyntax(string $phpParserMessage, int $line): self
    {
        return new self($phpParserMessage, $line);
    }

    public function getMessage(): string
    {
        return $this->message;
    }

    public function getLine(): int
    {
        return $this->line;
    }
}
