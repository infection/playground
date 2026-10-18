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

use App\Explore\DefaultExample;
use App\Infection\Runner;
use App\Infection\StaticAnalysisTool;
use App\Tests\Functional\ExampleFixtures;
use Generator;
use PHPUnit\Framework\Attributes\DataProvider;
use function str_replace;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

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
        self::createExample('OlderRun');
        self::createExample('BoundariesOfADiscount', killed: 3, escapedMutators: ['GreaterThan' => 1], config: '{"mutators": {"@default": true, "TrueValue": false}}');

        $crawler = $this->client->request('GET', '/explore');

        self::assertResponseIsSuccessful();
        self::assertSame(['BoundariesOfADiscount', 'OlderRun'], self::runNames($crawler));

        $card = $crawler->filter('.run-card')->first();
        self::assertStringContainsString('4 mutants', $card->text());
        self::assertStringContainsString('3 killed', $card->text());
        self::assertStringContainsString('1 escaped', $card->text());
        self::assertSame('75%', $card->filter('.run-score b')->text());
        self::assertSame('level-medium', $card->filter('.run-score b')->attr('class'));
        self::assertSame('-TrueValue', $card->filter('.tag-config')->text());
        self::assertSame('Latest', $crawler->filter('.explore-tabs [aria-current="page"]')->text());
    }

    public function test_it_shows_the_static_analysis_of_a_run(): void
    {
        self::createExample('WithoutStaticAnalysis');
        self::createExample('WithMago', staticAnalysisTool: StaticAnalysisTool::Mago);

        $crawler = $this->client->request('GET', '/explore');

        self::assertSame('Mago ' . Runner::CURRENT_MAGO_VERSION, $crawler->filter('.run-card')->eq(0)->filter('.tag-analysis')->text());
        self::assertCount(0, $crawler->filter('.run-card')->eq(1)->filter('.tag-analysis'));
    }

    public function test_it_names_a_run_after_its_class_and_method(): void
    {
        self::createExample(code: '<?php class Slugger { public function slugify(): string { return ""; } }');

        $crawler = $this->client->request('GET', '/explore');

        self::assertSame('Slugger::slugify()', $crawler->filter('.run-card h2')->text());
    }

    public function test_it_leaves_out_the_default_example_and_the_runs_without_mutants(): void
    {
        self::createExample('Shown');
        self::createExample('WithoutMutants', withLog: false);
        self::createExample('DefaultExample', config: DefaultExample::CONFIG, code: DefaultExample::CODE, test: DefaultExample::TEST);
        self::entityManager()->flush();

        $crawler = $this->client->request('GET', '/explore');

        self::assertSame(['Shown'], self::runNames($crawler));
        self::assertSame('3', $crawler->filter('.totals div')->eq(0)->filter('dd')->text());
    }

    public function test_it_lists_the_default_example_with_static_analysis(): void
    {
        self::createExample('DefaultExample', config: DefaultExample::CONFIG, code: DefaultExample::CODE, test: DefaultExample::TEST);
        self::createExample('DefaultExampleWithMago', config: DefaultExample::CONFIG, code: DefaultExample::CODE, test: DefaultExample::TEST, staticAnalysisTool: StaticAnalysisTool::Mago);
        self::entityManager()->flush();

        $crawler = $this->client->request('GET', '/explore');

        self::assertCount(1, $crawler->filter('.run-card'));
        self::assertSame('Mago ' . Runner::CURRENT_MAGO_VERSION, $crawler->filter('.run-card .tag-analysis')->text());
    }

    /**
     * @param list<string> $expected
     */
    #[DataProvider('provideRankings')]
    public function test_it_ranks_the_runs(string $sort, array $expected): void
    {
        self::createExample('FewMutantsAllKilled', killed: 4, escapedMutators: []);
        self::createExample('AllKilled', killed: 6, escapedMutators: []);
        self::createExample('ManySurvivors', killed: 2, escapedMutators: ['Plus' => 5]);
        self::createExample('SomeSurvivors', killed: 10, escapedMutators: ['Plus' => 2]);
        self::createExample('CustomConfig', killed: 1, escapedMutators: [], config: '{"mutators": {"@default": true, "Plus": false}}');
        self::createExample('OldVersion', killed: 30, escapedMutators: [], infectionVersion: '0.20.0');

        $crawler = $this->client->request('GET', '/explore?sort=' . $sort);

        self::assertResponseIsSuccessful();
        self::assertSame($expected, self::runNames($crawler));
    }

    public static function provideRankings(): Generator
    {
        yield 'perfect score: at least 5 mutants' => ['perfect', ['OldVersion', 'AllKilled']];

        yield 'most mutants' => ['mutants', ['OldVersion', 'SomeSurvivors', 'ManySurvivors', 'AllKilled', 'FewMutantsAllKilled', 'CustomConfig']];

        yield 'most survivors' => ['survivors', ['ManySurvivors', 'SomeSurvivors']];

        yield 'custom config' => ['config', ['CustomConfig']];

        yield 'latest version' => ['version', ['CustomConfig', 'SomeSurvivors', 'ManySurvivors', 'AllKilled', 'FewMutantsAllKilled']];

        yield 'unknown falls back to latest' => ['unknown', ['OldVersion', 'CustomConfig', 'SomeSurvivors', 'ManySurvivors', 'AllKilled', 'FewMutantsAllKilled']];
    }

    public function test_it_filters_by_escaped_mutator(): void
    {
        self::createExample('LogicalAndEscaped', escapedMutators: ['LogicalAnd' => 1, 'Plus' => 1]);
        self::createExample('PlusEscaped', escapedMutators: ['Plus' => 2]);
        self::createExample('NothingEscaped', escapedMutators: []);

        $crawler = $this->client->request('GET', '/explore?sort=mutants&escaped=LogicalAnd');

        self::assertSame(['LogicalAndEscaped'], self::runNames($crawler));
        self::assertSame('LogicalAnd (1 run)', $crawler->filter('#escaped-mutator option[selected]')->text());
        self::assertSame('/explore?sort=mutants&escaped=LogicalAnd', $crawler->filter('.explore-tab')->eq(2)->attr('href'));
    }

    public function test_it_lists_every_escaped_mutator_by_name(): void
    {
        foreach (['Plus', 'Minus', 'Zeta', 'Alpha', 'Beta', 'Gamma', 'Delta', 'Epsilon', 'Eta', 'Theta', 'Iota'] as $mutator) {
            self::createExample(escapedMutators: [$mutator => 1]);
        }

        self::createExample(escapedMutators: ['Zeta' => 1, 'Plus' => 2]);
        self::createExample(escapedMutators: ['Zeta' => 1]);

        $crawler = $this->client->request('GET', '/explore?sort=mutants');

        $form = $crawler->filter('form.mutator-filter');
        self::assertSame('/explore', $form->attr('action'));
        self::assertSame('mutants', $form->filter('input[name="sort"]')->attr('value'));
        self::assertSame(
            ['All mutators', 'Alpha (1 run)', 'Beta (1 run)', 'Delta (1 run)', 'Epsilon (1 run)', 'Eta (1 run)', 'Gamma (1 run)', 'Iota (1 run)', 'Minus (1 run)', 'Plus (2 runs)', 'Theta (1 run)', 'Zeta (3 runs)'],
            $form->filter('select[name="escaped"] option')->each(static fn (Crawler $node): string => $node->text()),
        );
        self::assertSame('', $form->filter('option')->first()->attr('value'));
        self::assertSame('Zeta', $form->filter('option')->last()->attr('value'));
        self::assertCount(0, $form->filter('option[selected]'));
    }

    public function test_it_keeps_a_filter_by_a_mutator_that_never_escaped(): void
    {
        self::createExample(escapedMutators: ['Plus' => 1]);

        $crawler = $this->client->request('GET', '/explore?escaped=Unknown');

        self::assertSame(['All mutators', 'Unknown (0 runs)', 'Plus (1 run)'], $crawler->filter('#escaped-mutator option')->each(static fn (Crawler $node): string => $node->text()));
        self::assertSame('Unknown', $crawler->filter('#escaped-mutator option[selected]')->attr('value'));
    }

    public function test_it_has_no_mutator_to_filter_by_before_one_escapes(): void
    {
        self::createExample(escapedMutators: []);

        $crawler = $this->client->request('GET', '/explore');

        self::assertCount(0, $crawler->filter('#escaped-mutator'));
        self::assertSame('none yet', $crawler->filter('.mutator-filter .filters-empty')->text());
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
            self::createExample('Run' . $i);
        }

        $crawler = $this->client->request('GET', '/explore');

        self::assertCount(20, $crawler->filter('.run-card'));
        self::assertSame('/explore?sort=latest&page=2', $crawler->filter('.pager a')->attr('href'));

        $crawler = $this->client->request('GET', '/explore?page=2');

        self::assertSame(['Run1'], self::runNames($crawler));
        self::assertSame('Previous page', $crawler->filter('.pager a')->text());
    }

    public function test_a_perfect_score_has_no_timeout(): void
    {
        self::createExample('KilledOrTimedOut', killed: 5, escapedMutators: [], timedOut: 1);
        self::createExample('AllKilled', killed: 6, escapedMutators: []);

        $crawler = $this->client->request('GET', '/explore?sort=perfect');

        self::assertSame(['AllKilled'], self::runNames($crawler));
    }

    public function test_it_rounds_the_scores_down(): void
    {
        self::createExample('AlmostPerfect', killed: 2499, escapedMutators: ['Plus' => 1]);
        self::createExample('TwoThirds', killed: 2, escapedMutators: ['Plus' => 1]);
        self::createExample('Half', killed: 1, escapedMutators: ['Plus' => 1]);

        $crawler = $this->client->request('GET', '/explore');

        self::assertSame(['50%', '66.6%', '99.9%'], $crawler->filter('.run-score b')->each(static fn ($node): string => $node->text()));
        self::assertSame('66.6%', $crawler->filter('.totals dd')->eq(1)->text());
    }

    public function test_it_says_when_no_run_matches(): void
    {
        $crawler = $this->client->request('GET', '/explore?escaped=LogicalAnd');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('No runs here yet', $crawler->filter('.run-empty')->text());
        self::assertStringContainsString('No run in this tab has an escaped LogicalAnd. Try another tab', $crawler->filter('.run-empty')->text());
        self::assertSame('Not enough runs yet.', $crawler->filter('.panel p')->eq(1)->text());
    }

    /**
     * @return list<string> the names of the runs, without the method of their fixture class
     */
    private static function runNames(Crawler $crawler): array
    {
        return $crawler->filter('.run-card h2')->each(static fn (Crawler $node): string => str_replace('::run()', '', $node->text()));
    }
}
