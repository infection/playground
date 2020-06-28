<?php

declare(strict_types=1);


namespace App\Infection;


final class RunResult
{
    private string $ansiOutput;

    private ?string $jsonLog;

    public function __construct(string $ansiOutput, ?string $jsonLog)
    {
        $this->ansiOutput = $ansiOutput;
        $this->jsonLog = $jsonLog;
    }

    public static function fromError(string $output): self
    {
        return new self($output, null);
    }

    public function getAnsiOutput(): string
    {
        return $this->ansiOutput;
    }

    public function getJsonLog(): ?string
    {
        return $this->jsonLog;
    }
}
