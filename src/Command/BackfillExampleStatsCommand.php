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

namespace App\Command;

use App\Entity\Example;
use App\Explore\ExampleStatsUpdater;
use Doctrine\ORM\EntityManagerInterface;
use function sprintf;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Fills the stats columns of the runs made before they existed. Safe to run again: it recomputes them.
 */
#[AsCommand(name: 'app:example:backfill-stats', description: 'Copy the numbers of every run\'s JSON log into the stats columns used by Explore')]
final class BackfillExampleStatsCommand
{
    private const BATCH_SIZE = 50;

    // ids start at 1: the first batch starts after it
    private const FIRST_ID_BEFORE_ALL = 0;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ExampleStatsUpdater $exampleStatsUpdater,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option(description: 'How many runs to load in memory at once')]
        int $batchSize = self::BATCH_SIZE,
    ): int {
        $lastId = self::FIRST_ID_BEFORE_ALL;
        $updated = 0;

        do {
            /** @var list<Example> $examples */
            $examples = $this->entityManager->createQueryBuilder()
                ->select('e')
                ->from(Example::class, 'e')
                ->where('e.id > :lastId')
                ->setParameter('lastId', $lastId)
                ->orderBy('e.id', 'ASC')
                ->setMaxResults($batchSize)
                ->getQuery()
                ->getResult();

            // without its body the loop never ends: the mutant can only time out
            /* @infection-ignore-all */
            foreach ($examples as $example) {
                $this->exampleStatsUpdater->update($example);
                $lastId = $example->getId();
                ++$updated;
            }

            $this->entityManager->flush();
            // the logs are big: keep the memory of one batch only
            $this->entityManager->clear();
        } while ($examples !== []);

        $io->success(sprintf('Updated the stats of %d runs.', $updated));

        return Command::SUCCESS;
    }
}
