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

namespace App\Controller;

use App\Entity\Example;
use App\Entity\ExampleMutator;
use App\Explore\ConfigChanges;
use App\Explore\Ranking;
use App\Explore\RunSubject;
use App\Infection\Runner;
use App\Repository\ExploreRepository;
use function array_map;
use function array_slice;
use function count;
use function is_string;
use function max;
use function mb_strlen;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * A gallery of the runs made on the playground, ranked by what they teach.
 */
class ExploreController extends AbstractController
{
    private const PAGE_SIZE = 20;

    private const FILTER_MUTATORS = 8;

    private const LEADERBOARD_SIZE = 6;

    /**
     * A mutator needs this many mutants over all runs to be on the leaderboard, so one run cannot top it.
     */
    private const LEADERBOARD_MIN_MUTANTS = 10;

    public function __construct(
        private readonly ExploreRepository $exploreRepository,
        private readonly RunSubject $runSubject,
    ) {
    }

    #[Route('/explore', name: 'explore_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $ranking = Ranking::tryFrom($request->query->getString('sort')) ?? Ranking::Latest;
        $page = max(1, $request->query->getInt('page', 1));

        $escaped = $request->query->get('escaped');
        $escapedMutator = is_string($escaped) && $escaped !== '' && mb_strlen($escaped) <= ExampleMutator::MAX_MUTATOR_LENGTH ? $escaped : null;

        // one more than a page tells whether there is a next one
        $examples = $this->exploreRepository->findRuns($ranking, $escapedMutator, ($page - 1) * self::PAGE_SIZE, self::PAGE_SIZE + 1);

        return $this->render('explore/index.html.twig', [
            'rankings' => Ranking::cases(),
            'ranking' => $ranking,
            'escapedMutator' => $escapedMutator,
            'filterMutators' => $this->exploreRepository->findFilterMutators(self::FILTER_MUTATORS),
            'runs' => array_map($this->describeRun(...), array_slice($examples, 0, self::PAGE_SIZE)),
            'page' => $page,
            'hasNextPage' => count($examples) > self::PAGE_SIZE,
            'totals' => [
                'runs' => $this->exploreRepository->countAll(),
                'medianMsi' => $this->exploreRepository->median('msi'),
                'medianMutants' => $this->exploreRepository->median('mutantsTotal'),
            ],
            'escapingMutators' => $this->exploreRepository->findMostEscapingMutators(self::LEADERBOARD_MIN_MUTANTS, self::LEADERBOARD_SIZE),
            'currentInfectionVersion' => Runner::CURRENT_INFECTION_VERSION,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function describeRun(Example $example): array
    {
        return [
            'idHash' => $example->getIdHash(),
            'title' => $this->runSubject->describe($example->getCode()) ?? 'Untitled run',
            'createdAt' => $example->getCreatedAt(),
            'infectionVersion' => $example->getInfectionVersion(),
            'total' => $example->getMutantsTotal(),
            'killed' => $example->getKilled(),
            'escaped' => $example->getEscaped(),
            'timedOut' => $example->getTimedOut(),
            'notCovered' => $example->getNotCovered(),
            'msi' => $example->getMsi(),
            'coveredMsi' => $example->getCoveredMsi(),
            'configChanges' => ConfigChanges::fromConfig($example->getConfig()),
        ];
    }
}
