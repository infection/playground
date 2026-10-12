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
use App\Repository\ExploreRepository;
use function count;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Hashids\Hashids;
use function sprintf;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Reported runs are hidden from Explore until reviewed here: either listed again, or unlisted for good.
 * Their links keep working either way.
 */
#[AsCommand(name: 'app:example:review', description: 'List the runs reported from Explore, or review one of them')]
final class ReviewExampleCommand
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ExploreRepository $exploreRepository,
        private readonly Hashids $hashids,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument(description: 'The hash of the run, as in /r/{hash}. Lists the reported runs when omitted.')]
        ?string $run = null,
        #[Option(description: 'Show the run in Explore again')]
        bool $restore = false,
        #[Option(description: 'Remove the run from Explore for good')]
        bool $unlist = false,
        #[Option(description: 'Show every reported run in Explore again, to undo a flood of reports')]
        bool $restoreAll = false,
        #[Option(description: 'With --restore-all: only the runs reported since this date, as "2026-10-12 14:00"')]
        ?string $since = null,
    ): int {
        if ($restoreAll || $since !== null) {
            return $this->restoreAll($io, $run, $restore, $unlist, $restoreAll, $since);
        }

        if ($run === null) {
            return $this->listReported($io);
        }

        if ($restore === $unlist) {
            $io->error('Pass either --restore or --unlist.');

            return Command::INVALID;
        }

        $ids = $this->hashids->decode($run);
        $example = $ids === [] ? null : $this->entityManager->find(Example::class, $ids[0]);

        if (!$example instanceof Example) {
            $io->error(sprintf('There is no run "%s".', $run));

            return Command::FAILURE;
        }

        if ($restore) {
            $example->clearReport();
        } else {
            // the report stays: a new run of the same input lists it again, but keeps it hidden
            $example->unlist();
        }

        $this->entityManager->flush();

        $io->success(sprintf($restore ? 'Run %s is back in Explore.' : 'Run %s is removed from Explore.', $run));

        return Command::SUCCESS;
    }

    private function restoreAll(SymfonyStyle $io, ?string $run, bool $restore, bool $unlist, bool $restoreAll, ?string $since): int
    {
        if (!$restoreAll || $run !== null || $restore || $unlist) {
            $io->error('Pass --restore-all, optionally with --since, and no run, --restore or --unlist.');

            return Command::INVALID;
        }

        $sinceDate = $since === null ? null : self::parseDate($since);

        if ($sinceDate === false) {
            $io->error(sprintf('"%s" is not a date as "2026-10-12" or "2026-10-12 14:00".', $since));

            return Command::INVALID;
        }

        $examples = $this->exploreRepository->findReported($sinceDate);

        foreach ($examples as $example) {
            $example->clearReport();
        }

        $this->entityManager->flush();

        $io->success(sprintf('%d reported runs are back in Explore.', count($examples)));

        return Command::SUCCESS;
    }

    private static function parseDate(string $date): DateTimeImmutable|false
    {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d H:i', $date);

        return $parsed !== false ? $parsed : DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    }

    private function listReported(SymfonyStyle $io): int
    {
        $rows = [];

        foreach ($this->exploreRepository->findReported() as $example) {
            $rows[] = [
                (string) $example->getIdHash(),
                $example->getReportedAt()?->format('Y-m-d H:i') ?? '',
                $example->getTitle() ?? '',
            ];
        }

        if ($rows === []) {
            $io->success('No run is waiting for a review.');

            return Command::SUCCESS;
        }

        $io->table(['Run', 'Reported at', 'Title'], $rows);

        return Command::SUCCESS;
    }
}
