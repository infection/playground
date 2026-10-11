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

namespace App\Tests\Functional\Controller;

use App\Entity\Example;
use App\Explore\DefaultExample;
use App\Tests\Functional\ExampleFixtures;
use Generator;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ExploreControllerTest extends WebTestCase
{
    use ExampleFixtures;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->catchExceptions(false);

        self::purgeExamples();
    }

    public function test_it_lists_the_latest_runs_with_their_numbers(): void
    {
        self::createExample('Older run');
        self::createExample('Boundaries of a discount', killed: 3, escapedMutators: ['GreaterThan' => 1], config: '{"mutators": {"@default": true, "TrueValue": false}}');

        $crawler = $this->client->request('GET', '/explore');

        self::assertResponseIsSuccessful();
        self::assertSame(['Boundaries of a discount', 'Older run'], $crawler->filter('.run-card h2')->each(static fn ($node): string => $node->text()));

        $card = $crawler->filter('.run-card')->first();
        self::assertStringContainsString('4 mutants', $card->text());
        self::assertStringContainsString('3 killed', $card->text());
        self::assertStringContainsString('1 escaped', $card->text());
        self::assertSame('75%', $card->filter('.run-score b')->text());
        self::assertSame('level-medium', $card->filter('.run-score b')->attr('class'));
        self::assertSame('-TrueValue', $card->filter('.tag-config')->text());
        self::assertSame('Latest', $crawler->filter('.explore-tabs [aria-current="page"]')->text());
    }

    public function test_it_names_a_run_without_a_title_after_its_class_and_method(): void
    {
        self::createExample(code: '<?php class Slugger { public function slugify(): string { return ""; } }');

        $crawler = $this->client->request('GET', '/explore');

        self::assertSame('Slugger::slugify()', $crawler->filter('.run-card h2')->text());
        self::assertCount(0, $crawler->filter('.run-subject'));
    }

    public function test_it_leaves_out_the_runs_that_are_not_listed(): void
    {
        self::createExample('Listed');
        self::createExample('Reported')->report();
        self::createExample('Old unlisted link')->unlist();
        self::createExample('Without mutants', withLog: false);
        self::createExample('Default example', config: DefaultExample::CONFIG, code: DefaultExample::CODE, test: DefaultExample::TEST);
        self::entityManager()->flush();

        $crawler = $this->client->request('GET', '/explore');

        self::assertSame(['Listed'], $crawler->filter('.run-card h2')->each(static fn ($node): string => $node->text()));
        self::assertSame('5', $crawler->filter('.totals div')->eq(0)->filter('dd')->text());
        self::assertSame('1', $crawler->filter('.totals div')->eq(1)->filter('dd')->text());
    }

    /**
     * @param list<string> $expected
     */
    #[DataProvider('provideRankings')]
    public function test_it_ranks_the_runs(string $sort, array $expected): void
    {
        self::createExample('Few mutants, all killed', killed: 4, escapedMutators: []);
        self::createExample('All killed', killed: 6, escapedMutators: []);
        self::createExample('Many survivors', killed: 2, escapedMutators: ['Plus' => 5]);
        self::createExample('Some survivors', killed: 10, escapedMutators: ['Plus' => 2]);
        self::createExample('Custom config', killed: 1, escapedMutators: [], config: '{"mutators": {"@default": true, "Plus": false}}');
        self::createExample('Old version', killed: 30, escapedMutators: [], infectionVersion: '0.20.0');

        $crawler = $this->client->request('GET', '/explore?sort=' . $sort);

        self::assertResponseIsSuccessful();
        self::assertSame($expected, $crawler->filter('.run-card h2')->each(static fn ($node): string => $node->text()));
    }

    public static function provideRankings(): Generator
    {
        yield 'perfect score: at least 5 mutants' => ['perfect', ['Old version', 'All killed']];

        yield 'most mutants' => ['mutants', ['Old version', 'Some survivors', 'Many survivors', 'All killed', 'Few mutants, all killed', 'Custom config']];

        yield 'most survivors' => ['survivors', ['Many survivors', 'Some survivors']];

        yield 'custom config' => ['config', ['Custom config']];

        yield 'latest version' => ['version', ['Custom config', 'Some survivors', 'Many survivors', 'All killed', 'Few mutants, all killed']];

        yield 'unknown falls back to latest' => ['unknown', ['Old version', 'Custom config', 'Some survivors', 'Many survivors', 'All killed', 'Few mutants, all killed']];
    }

    public function test_it_filters_by_escaped_mutator(): void
    {
        self::createExample('LogicalAnd escaped', escapedMutators: ['LogicalAnd' => 1, 'Plus' => 1]);
        self::createExample('Plus escaped', escapedMutators: ['Plus' => 2]);
        self::createExample('Nothing escaped', escapedMutators: []);

        $crawler = $this->client->request('GET', '/explore?sort=mutants&escaped=LogicalAnd');

        self::assertSame(['LogicalAnd escaped'], $crawler->filter('.run-card h2')->each(static fn ($node): string => $node->text()));
        self::assertSame('LogicalAnd', $crawler->filter('.explore-head .chip[aria-pressed="true"]')->text());
        // the chips follow the number of runs a mutator escaped in
        self::assertSame(['Plus', 'LogicalAnd'], $crawler->filter('.explore-head .chip')->each(static fn ($node): string => $node->text()));
        self::assertSame('/explore?sort=mutants', $crawler->filter('.explore-head .chip[aria-pressed="true"]')->attr('href'));
        self::assertSame('/explore?sort=mutants&escaped=LogicalAnd', $crawler->filter('.explore-tabs a')->eq(2)->attr('href'));
    }

    public function test_it_ranks_the_mutators_that_escape_most(): void
    {
        self::createExample(killed: 0, escapedMutators: ['Plus' => 6, 'Minus' => 10]);
        self::createExample(killed: 0, escapedMutators: ['Plus' => 6, 'LogicalAnd' => 3]);

        $crawler = $this->client->request('GET', '/explore');

        // the made-up logs list the escaped mutants only, so every mutant escaped; LogicalAnd has too few mutants
        self::assertSame(['Minus', 'Plus'], $crawler->filter('.leaderboard a')->each(static fn ($node): string => $node->text()));
        self::assertSame('10 of 10 mutants escaped', $crawler->filter('.leaderboard a')->first()->attr('title'));
    }

    public function test_it_pages_the_runs(): void
    {
        for ($i = 1; $i <= 21; ++$i) {
            self::createExample('Run ' . $i);
        }

        $crawler = $this->client->request('GET', '/explore');

        self::assertCount(20, $crawler->filter('.run-card'));
        self::assertSame('/explore?sort=latest&page=2', $crawler->filter('.pager a')->attr('href'));

        $crawler = $this->client->request('GET', '/explore?page=2');

        self::assertSame(['Run 1'], $crawler->filter('.run-card h2')->each(static fn ($node): string => $node->text()));
        self::assertSame('Newer page', $crawler->filter('.pager a')->text());
    }

    public function test_it_says_when_no_run_matches(): void
    {
        $crawler = $this->client->request('GET', '/explore?escaped=LogicalAnd');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('No runs here yet', $crawler->filter('.run-empty')->text());
        self::assertSame('Not enough runs yet.', $crawler->filter('.panel p')->eq(1)->text());
    }

    public function test_a_report_hides_a_run_from_explore(): void
    {
        $example = self::createExample('To report');

        $crawler = $this->client->request('GET', '/r/' . $example->getIdHash());
        self::assertCount(1, $crawler->filter('form[action$="/report"]'));

        $this->client->submit($crawler->filter('form[action$="/report"]')->form());
        self::assertResponseRedirects('/r/' . $example->getIdHash());

        $crawler = $this->client->followRedirect();
        self::assertSame('Reported', $crawler->filter('.reported')->text());
        self::assertCount(0, $crawler->filter('form[action$="/report"]'));

        $crawler = $this->client->request('GET', '/explore');
        self::assertCount(0, $crawler->filter('a.run-card'));
    }

    public function test_an_unlisted_run_has_no_report_button(): void
    {
        $example = self::createExample();
        $example->unlist();
        self::entityManager()->flush();

        $crawler = $this->client->request('GET', '/r/' . $example->getIdHash());

        self::assertCount(0, $crawler->filter('form[action$="/report"]'));
        self::assertCount(0, $crawler->filter('.reported'));
    }

    public function test_it_cannot_report_a_run_that_does_not_exist(): void
    {
        $this->client->catchExceptions(true);

        $this->client->request('POST', '/r/zzzzzzzz/report');

        self::assertResponseStatusCodeSame(404);
    }

    public function test_a_new_run_of_an_unlisted_input_lists_it(): void
    {
        $example = self::createExample();
        $example->unlist();
        self::entityManager()->flush();

        $this->client->request('GET', '/');
        $this->client->submitForm('create_example[mutate]', [
            'create_example[code]' => $example->getCode(),
            'create_example[test]' => $example->getTest(),
            'create_example[config]' => $example->getConfig(),
            'create_example[title]' => '  Listed again  ',
        ]);

        self::assertResponseRedirects('/r/' . $example->getIdHash());

        self::entityManager()->clear();
        $reloaded = self::entityManager()->find(Example::class, $example->getId());

        self::assertInstanceOf(Example::class, $reloaded);
        self::assertTrue($reloaded->isListed());
        self::assertSame('Listed again', $reloaded->getTitle());
    }
}
