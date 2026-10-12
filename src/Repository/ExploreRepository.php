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

namespace App\Repository;

use App\Entity\Example;
use App\Entity\ExampleMutator;
use App\Explore\Ranking;
use App\Infection\Runner;
use function array_map;
use function array_sum;
use function count;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use function intdiv;

/**
 * The queries of the Explore page. It shows every run that Infection got to the end of, but the default
 * example; the totals and the leaderboard count every run.
 */
final class ExploreRepository
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    /**
     * @return list<Example>
     */
    public function findRuns(Ranking $ranking, ?string $escapedMutator, int $offset, int $limit): array
    {
        $queryBuilder = $this->runsQueryBuilder()
            ->setFirstResult($offset)
            ->setMaxResults($limit);

        match ($ranking) {
            Ranking::Latest => null,
            Ranking::PerfectScore => $queryBuilder
                // Infection counts timeouts as detected, a perfect score kills every mutant
                ->andWhere('e.msi >= 100')
                ->andWhere('e.timedOut = 0')
                ->andWhere('e.mutantsTotal >= :minMutants')
                ->setParameter('minMutants', Ranking::MIN_MUTANTS)
                ->orderBy('e.mutantsTotal', 'DESC'),
            Ranking::MostMutants => $queryBuilder
                ->orderBy('e.mutantsTotal', 'DESC'),
            Ranking::MostSurvivors => $queryBuilder
                ->andWhere('e.escaped > 0')
                ->andWhere('e.mutantsTotal >= :minMutants')
                ->setParameter('minMutants', Ranking::MIN_MUTANTS)
                ->orderBy('e.escaped', 'DESC')
                ->addOrderBy('e.msi', 'ASC'),
            Ranking::CustomConfig => $queryBuilder
                ->andWhere('e.hasCustomConfig = true'),
            Ranking::LatestVersion => $queryBuilder
                ->andWhere('e.infectionVersion = :version')
                ->setParameter('version', Runner::CURRENT_INFECTION_VERSION),
        };

        // the newest first among equals, and as the order of Latest
        $queryBuilder
            ->addOrderBy('e.createdAt', 'DESC')
            ->addOrderBy('e.id', 'DESC');

        if ($escapedMutator !== null) {
            $queryBuilder
                ->andWhere('EXISTS (SELECT 1 FROM ' . ExampleMutator::class . ' em WHERE em.example = e AND em.mutator = :mutator AND em.escaped > 0)')
                ->setParameter('mutator', $escapedMutator);
        }

        /** @var list<Example> $examples */
        $examples = $queryBuilder->getQuery()->getResult();

        return $examples;
    }

    public function countAll(): int
    {
        return (int) $this->entityManager->createQueryBuilder()
            ->select('COUNT(e.id)')
            ->from(Example::class, 'e')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * The median of a stats column over every run that generated mutants.
     */
    public function median(string $field): ?float
    {
        $count = (int) $this->runsWithMutantsQueryBuilder()
            ->select('COUNT(e.id)')
            ->getQuery()
            ->getSingleScalarResult();

        if ($count === 0) {
            return null;
        }

        /** @var list<float|int|string> $middle */
        $middle = $this->runsWithMutantsQueryBuilder()
            ->select('e.' . $field)
            ->orderBy('e.' . $field, 'ASC')
            ->setFirstResult(intdiv($count - 1, 2))
            ->setMaxResults($count % 2 === 0 ? 2 : 1)
            ->getQuery()
            ->getSingleColumnResult();

        return array_sum(array_map(floatval(...), $middle)) / count($middle);
    }

    /**
     * The mutators whose mutants escape most often, over every run ever made.
     *
     * @return list<array{mutator: string, escaped: int, total: int}>
     */
    public function findMostEscapingMutators(int $minMutants, int $limit): array
    {
        /** @var list<array{mutator: string, escaped: int|string, total: int|string}> $rows */
        $rows = $this->entityManager->createQueryBuilder()
            ->select(
                'em.mutator AS mutator',
                'SUM(em.escaped) AS escaped',
                'SUM(em.killed + em.escaped + em.timedOut + em.notCovered) AS total',
                'SUM(em.escaped) / SUM(em.killed + em.escaped + em.timedOut + em.notCovered) AS HIDDEN escapedShare',
            )
            ->from(ExampleMutator::class, 'em')
            ->groupBy('em.mutator')
            ->having('SUM(em.killed + em.escaped + em.timedOut + em.notCovered) >= :minMutants')
            ->andHaving('SUM(em.escaped) > 0')
            ->setParameter('minMutants', $minMutants)
            ->orderBy('escapedShare', 'DESC')
            ->addOrderBy('mutator', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getArrayResult();

        return array_map(
            static fn (array $row): array => ['mutator' => $row['mutator'], 'escaped' => (int) $row['escaped'], 'total' => (int) $row['total']],
            $rows,
        );
    }

    /**
     * The mutators that escaped in the runs of Explore: the choices of the mutator filter.
     *
     * @return array<string, int> mutator => the runs it escaped in
     */
    public function findEscapedMutators(): array
    {
        /** @var list<array{mutator: string, runs: int|string}> $rows */
        $rows = $this->entityManager->createQueryBuilder()
            ->select('em.mutator AS mutator', 'COUNT(em.id) AS runs')
            ->from(ExampleMutator::class, 'em')
            ->join('em.example', 'e')
            ->where('em.escaped > 0')
            ->andWhere($this->shownCondition())
            ->groupBy('em.mutator')
            ->getQuery()
            ->getArrayResult();

        $mutators = [];

        foreach ($rows as $row) {
            $mutators[$row['mutator']] = (int) $row['runs'];
        }

        return $mutators;
    }

    private function runsQueryBuilder(): QueryBuilder
    {
        return $this->entityManager->createQueryBuilder()
            ->select('e')
            ->from(Example::class, 'e')
            ->where($this->shownCondition());
    }

    private function shownCondition(): string
    {
        return 'e.isDefaultExample = false AND e.mutantsTotal IS NOT NULL AND e.idHash IS NOT NULL';
    }

    private function runsWithMutantsQueryBuilder(): QueryBuilder
    {
        return $this->entityManager->createQueryBuilder()
            ->from(Example::class, 'e')
            ->where('e.mutantsTotal > 0');
    }
}
