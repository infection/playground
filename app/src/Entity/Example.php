<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * @ORM\Entity()
 * @ORM\HasLifecycleCallbacks()
 */
class Example
{
    /**
     * @ORM\Id()
     * @ORM\GeneratedValue()
     * @ORM\Column(type="integer")
     *
     * @var int
     */
    private $id;

    /**
     * @ORM\Column(type="text")
     *
     * @var string
     */
    private $code;

    /**
     * @ORM\Column(type="text")
     *
     * @var string
     */
    private $test;

    /**
     * @ORM\Column(type="text")
     *
     * @var string
     */
    private $config;

    /**
     * @ORM\Column(type="text")
     *
     * @var string
     */
    private $resultOutput;

    /**
     * @ORM\Column(type="datetime_immutable", options={"default"="CURRENT_TIMESTAMP"})
     *
     * @var \DateTimeImmutable
     */
    private $createdAt;

    public function __construct(string $code, string $test, string $config)
    {
        $this->code = $code;
        $this->test = $test;
        $this->config = $config;
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

    /**
     * @ORM\PrePersist()
     */
    public function setCreatedAtToCurrentDateTime(): void
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function updateResultOutput(string $resultOutput): void
    {
        $this->resultOutput = $resultOutput;
    }
}
