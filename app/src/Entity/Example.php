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

namespace App\Entity;

use DateTimeImmutable;
use DateTimeInterface;
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
    private DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'string', length: 32, unique: true)]
    private string $inputHash;

    #[ORM\Column(type: 'string', length: 10, nullable: true)]
    private ?string $idHash = null;

    #[ORM\Column(type: 'string', length: 11, nullable: true)]
    private ?string $infectionVersion;

    #[ORM\Column(type: 'string', length: 11, nullable: true)]
    private ?string $phpunitVersion;

    #[ORM\Column(type: 'string', length: 12, options: ['default' => '8.1.3'])]
    private string $phpVersion;

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

    public function getCreatedAt(): DateTimeInterface
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
        $this->createdAt = new DateTimeImmutable();
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
