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
use App\Tests\Functional\ExampleFixtures;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class ReviewExampleCommandTest extends KernelTestCase
{
    use ExampleFixtures;

    private CommandTester $tester;

    protected function setUp(): void
    {
        self::purgeExamples();

        $this->tester = new CommandTester((new Application(self::bootKernel()))->find('app:example:review'));
    }

    public function test_it_lists_the_runs_waiting_for_a_review(): void
    {
        self::assertSame(Command::SUCCESS, $this->tester->execute([]));
        self::assertStringContainsString('No run is waiting for a review.', $this->tester->getDisplay());

        $reported = self::createExample('Spam');
        $reported->report();
        $unlisted = self::createExample('Already reviewed');
        $unlisted->report();
        $unlisted->unlist();
        self::createExample('Not reported');
        self::entityManager()->flush();

        self::assertSame(Command::SUCCESS, $this->tester->execute([]));

        $display = $this->tester->getDisplay();
        self::assertStringContainsString((string) $reported->getIdHash(), $display);
        self::assertStringContainsString('Spam', $display);
        self::assertStringNotContainsString('Already reviewed', $display);
        self::assertStringNotContainsString('Not reported', $display);
    }

    public function test_it_restores_a_run(): void
    {
        $example = self::createExample();
        $example->report();
        self::entityManager()->flush();

        self::assertSame(Command::SUCCESS, $this->tester->execute(['run' => $example->getIdHash(), '--restore' => true]));

        $reloaded = $this->reload($example);
        self::assertNull($reloaded->getReportedAt());
        self::assertTrue($reloaded->isListed());
    }

    public function test_it_unlists_a_run_and_keeps_it_reported(): void
    {
        $example = self::createExample();
        $example->report();
        self::entityManager()->flush();

        self::assertSame(Command::SUCCESS, $this->tester->execute(['run' => $example->getIdHash(), '--unlist' => true]));

        $reloaded = $this->reload($example);
        self::assertNotNull($reloaded->getReportedAt());
        self::assertFalse($reloaded->isListed());
    }

    public function test_it_needs_one_decision(): void
    {
        $example = self::createExample();

        self::assertSame(Command::INVALID, $this->tester->execute(['run' => $example->getIdHash()]));
        self::assertSame(Command::INVALID, $this->tester->execute(['run' => $example->getIdHash(), '--restore' => true, '--unlist' => true]));
    }

    public function test_it_fails_for_an_unknown_run(): void
    {
        self::assertSame(Command::FAILURE, $this->tester->execute(['run' => 'zzzzzzzz', '--restore' => true]));
        self::assertStringContainsString('There is no run "zzzzzzzz".', $this->tester->getDisplay());
    }

    private function reload(Example $example): Example
    {
        self::entityManager()->clear();

        $reloaded = self::entityManager()->find(Example::class, $example->getId());
        self::assertInstanceOf(Example::class, $reloaded);

        return $reloaded;
    }
}
