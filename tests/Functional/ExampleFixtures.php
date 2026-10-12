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

namespace App\Tests\Functional;

use App\Entity\Example;
use App\Entity\ExampleMutator;
use App\Explore\ExampleStatsUpdater;
use App\Infection\Runner;
use function assert;
use function count;
use Doctrine\ORM\EntityManagerInterface;
use Hashids\Hashids;
use function json_encode;
use Symfony\Component\DependencyInjection\ContainerInterface;
use function uniqid;

/**
 * Runs saved straight to the database, with a JSON log made up for the test instead of running Infection.
 */
trait ExampleFixtures
{
    abstract protected static function getContainer(): ContainerInterface;

    private static function purgeExamples(): void
    {
        $entityManager = self::entityManager();

        $entityManager->createQuery('DELETE FROM ' . ExampleMutator::class)->execute();
        $entityManager->createQuery('DELETE FROM ' . Example::class)->execute();
    }

    /**
     * @param array<string, int> $escapedMutators mutator name => escaped mutants
     */
    private static function createExample(
        ?string $class = null,
        int $killed = 4,
        array $escapedMutators = ['Plus' => 1],
        int $timedOut = 0,
        string $config = '{"mutators": {"@default": true}}',
        ?string $code = null,
        string $test = '<?php // test',
        string $infectionVersion = Runner::CURRENT_INFECTION_VERSION,
        bool $withLog = true,
    ): Example {
        $entityManager = self::entityManager();

        $example = new Example(
            $code ?? '<?php class ' . ($class ?? 'Run' . uniqid()) . ' { public function run(): void {} }',
            $test,
            $config,
            $infectionVersion,
            Runner::CURRENT_PHPUNIT_VERSION,
            Runner::CURRENT_PHP_VERSION,
        );

        $entityManager->persist($example);
        $entityManager->flush();

        $escaped = [];

        foreach ($escapedMutators as $mutator => $count) {
            for ($i = 0; $i < $count; ++$i) {
                $escaped[] = ['mutator' => ['mutatorName' => $mutator]];
            }
        }

        $total = $killed + $timedOut + count($escaped);
        $msi = $total === 0 ? 0 : ($killed + $timedOut) / $total * 100;

        $hashids = self::getContainer()->get(Hashids::class);
        assert($hashids instanceof Hashids);

        $example->setIdHash($hashids->encode($example->getId()));

        if ($withLog) {
            $example->updateJsonLog((string) json_encode([
                'stats' => [
                    'totalMutantsCount' => $total,
                    'killedCount' => $killed,
                    'escapedCount' => count($escaped),
                    'timeOutCount' => $timedOut,
                    'msi' => $msi,
                    'coveredCodeMsi' => $msi,
                ],
                'escaped' => $escaped,
            ]));
        }

        $exampleStatsUpdater = self::getContainer()->get(ExampleStatsUpdater::class);
        assert($exampleStatsUpdater instanceof ExampleStatsUpdater);

        $exampleStatsUpdater->update($example);
        $entityManager->flush();

        return $example;
    }

    private static function entityManager(): EntityManagerInterface
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        assert($entityManager instanceof EntityManagerInterface);

        return $entityManager;
    }
}
