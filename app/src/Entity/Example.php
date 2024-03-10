<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use function md5;

#[ORM\Entity]
#[ORM\HasLifecycleCallbacks]
class Example
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private int $id;

    #[ORM\Column(type: 'text')]
    private string $code;

    #[ORM\Column(type: 'text')]
    private string $test;

    #[ORM\Column(type: 'text')]
    private string $config;

    #[ORM\Column(type: 'text')]
    private string $resultOutput = '';

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $jsonLog = null;

    #[ORM\Column(type: 'datetime_immutable', options: ['default' => 'CURRENT_TIMESTAMP'])]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'string', length: 32, unique: true)]
    private string $inputHash;

    #[ORM\Column(type: 'string', length: 10, nullable: true)]
    private ?string $idHash = null;

    #[ORM\Column(type: 'string', length: 11, nullable: true)]
    private ?string $infectionVersion;

    #[ORM\Column(type: 'string', length: 11, nullable: true)]
    private ?string $phpunitVersion;

    #[ORM\Column(type: 'string', length: 12, nullable: true, options: ['default' => '8.1.3'])]
    private ?string $phpVersion;

    public function __construct(string $code, string $test, string $config, string $infectionVersion, string $phpunitVersion, string $phpVersion)
    {
        $this->code = $code;
        $this->test = $test;
        $this->config = $config;
        $this->infectionVersion = $infectionVersion;
        $this->phpunitVersion = $phpunitVersion;
        $this->phpVersion = $phpVersion;
        $this->inputHash = self::hashInput($code, $test, $config, $infectionVersion, $phpunitVersion, $phpVersion);
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function getTest(): string
    {
        return $this->test;
    }

    public function getConfig(): string
    {
        return $this->config;
    }

    public function getCreatedAt(): \DateTimeInterface
    {
        return $this->createdAt;
    }

    public function getResultOutput(): string
    {
        return $this->resultOutput;
    }

    #[ORM\PrePersist]
    public function setCreatedAtToCurrentDateTime(): void
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function updateResultOutput(string $resultOutput): void
    {
        $this->resultOutput = $resultOutput;
    }

    public function updateJsonLog(?string $jsonLog): void
    {
        $this->jsonLog = $jsonLog;
    }

    public function getJsonLog(): ?string
    {
        return $this->jsonLog;
    }

    public function getInputHash(): string
    {
        return $this->inputHash;
    }

    public static function hashInput(string $code, string $test, string $config, string $infectionVersion, string $phpunitVersion, string $phpVersion): string
    {
        return md5($code . $test . $config . $infectionVersion . $phpunitVersion . $phpVersion);
    }

    public function getIdHash(): ?string
    {
        return $this->idHash;
    }

    public function setIdHash(string $idHash): void
    {
        $this->idHash = $idHash;
    }

    public function getInfectionVersion(): ?string
    {
        return $this->infectionVersion;
    }

    public function getPhpunitVersion(): ?string
    {
        return $this->phpunitVersion;
    }

    public function getPhpVersion(): string
    {
        return $this->phpVersion;
    }
}
