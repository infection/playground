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

use Doctrine\ORM\Mapping as ORM;

/**
 * The mutants of one mutator in one run: drives the mutator filter and the leaderboard of Explore.
 */
#[ORM\Entity]
#[ORM\Index(name: 'idx_example_mutator_mutator', columns: ['mutator'])]
class ExampleMutator
{
    public const MAX_MUTATOR_LENGTH = 64;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private int $id;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: Example::class)]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Example $example,
        #[ORM\Column(type: 'string', length: self::MAX_MUTATOR_LENGTH)]
        private string $mutator,
        #[ORM\Column(name: 'killed_count', type: 'integer')]
        private int $killed,
        #[ORM\Column(name: 'escaped_count', type: 'integer')]
        private int $escaped,
        #[ORM\Column(name: 'timed_out_count', type: 'integer')]
        private int $timedOut,
        #[ORM\Column(name: 'not_covered_count', type: 'integer')]
        private int $notCovered,
    ) {
    }

    public function getExample(): Example
    {
        return $this->example;
    }

    public function getMutator(): string
    {
        return $this->mutator;
    }

    public function getEscaped(): int
    {
        return $this->escaped;
    }
}
