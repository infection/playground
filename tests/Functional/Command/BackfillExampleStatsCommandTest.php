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

namespace App\Tests\Functional\Command;

use App\Entity\Example;
use App\Entity\ExampleMutator;
use App\Tests\Functional\ExampleFixtures;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class BackfillExampleStatsCommandTest extends KernelTestCase
{
    use ExampleFixtures;

    protected function setUp(): void
    {
        self::purgeExamples();
    }

    public function test_it_fills_the_stats_of_the_runs_made_before_them(): void
    {
        $withLog = self::createExample(killed: 3, escapedMutators: ['Plus' => 1], config: '{"mutators": {"@default": true, "Plus": false}}');
        $withoutLog = self::createExample(withLog: false);

        // as the migration leaves the runs made before Explore
        self::entityManager()->getConnection()->executeStatement('UPDATE example SET mutants_total = NULL, killed_count = NULL, escaped_count = NULL, msi = NULL, has_custom_config = 0');
        self::entityManager()->getConnection()->executeStatement('DELETE FROM example_mutator');
        self::entityManager()->clear();

        $tester = new CommandTester((new Application(self::bootKernel()))->find('app:example:backfill-stats'));

        self::assertSame(0, $tester->execute([]));
        self::assertStringContainsString('Updated the stats of 2 runs.', $tester->getDisplay());

        self::entityManager()->clear();

        $reloaded = self::entityManager()->find(Example::class, $withLog->getId());
        self::assertInstanceOf(Example::class, $reloaded);
        self::assertSame(4, $reloaded->getMutantsTotal());
        self::assertSame(3, $reloaded->getKilled());
        self::assertSame(1, $reloaded->getEscaped());
        self::assertSame(75.0, $reloaded->getMsi());
        self::assertTrue($reloaded->hasCustomConfig());

        $reloaded = self::entityManager()->find(Example::class, $withoutLog->getId());
        self::assertInstanceOf(Example::class, $reloaded);
        self::assertNull($reloaded->getMutantsTotal());

        $mutators = self::entityManager()->getRepository(ExampleMutator::class)->findAll();
        self::assertCount(1, $mutators);
        self::assertSame('Plus', $mutators[0]->getMutator());
        self::assertSame($withLog->getId(), $mutators[0]->getExample()->getId());
        self::assertSame(1, $mutators[0]->getEscaped());

        // running it again replaces the mutators instead of adding them twice
        self::assertSame(0, $tester->execute([]));
        self::assertCount(1, self::entityManager()->getRepository(ExampleMutator::class)->findAll());
    }

    public function test_it_updates_the_runs_in_several_batches(): void
    {
        $first = self::createExample(killed: 1);
        $second = self::createExample(killed: 2);
        $third = self::createExample(killed: 3);

        self::entityManager()->getConnection()->executeStatement('UPDATE example SET mutants_total = NULL, killed_count = NULL');
        self::entityManager()->clear();

        $tester = new CommandTester((new Application(self::bootKernel()))->find('app:example:backfill-stats'));

        self::assertSame(0, $tester->execute(['--batch-size' => 2]));
        self::assertStringContainsString('Updated the stats of 3 runs.', $tester->getDisplay());

        self::entityManager()->clear();

        foreach ([1 => $first, 2 => $second, 3 => $third] as $killed => $example) {
            $reloaded = self::entityManager()->find(Example::class, $example->getId());
            self::assertInstanceOf(Example::class, $reloaded);
            self::assertSame($killed, $reloaded->getKilled());
        }
    }
}
