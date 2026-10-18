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
use App\Infection\Runner;
use App\Infection\StaticAnalysisTool;
use App\Request\CreateExampleRequest;
use function array_map;
use function basename;
use Doctrine\ORM\EntityManagerInterface;
use function file_get_contents;
use Generator;
use Hashids\Hashids;
use function ini_get;
use function ini_set;
use function json_decode;
use const JSON_THROW_ON_ERROR;
use const PHP_INT_MAX;
use const PHP_MAJOR_VERSION;
use const PHP_MINOR_VERSION;
use PHPUnit\Framework\Attributes\DataProvider;
use function random_int;
use function set_time_limit;
use function sprintf;
use function str_repeat;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class PlaygroundControllerTest extends WebTestCase
{
    public function test_it_creates_a_mutation_example(): void
    {
        $client = static::createClient();
        $client->catchExceptions(false);

        $client->request('GET', '/');
        static::assertSame(200, $client->getResponse()->getStatusCode());

        $client->submitForm('create_example[mutate]', [
            'create_example[code]' => 'code',
            'create_example[test]' => 'test',
            'create_example[config]' => '{"mutators": {"@default": true}}',
        ]);

        static::assertSame(302, $client->getResponse()->getStatusCode());
        static::assertStringNotContainsString('This value should not be blank', (string) $client->getResponse()->getContent());
    }

    public function test_the_request_outlives_the_infection_process(): void
    {
        $client = static::createClient();
        $client->catchExceptions(false);

        $client->request('GET', '/');

        // the web server has a time limit, the CLI does not
        ini_set('max_execution_time', '30');

        try {
            $client->submitForm('create_example[mutate]', [
                'create_example[code]' => 'code ' . random_int(1, PHP_INT_MAX),
                'create_example[test]' => 'test',
                'create_example[config]' => '{"mutators": {"@default": true}}',
                'create_example[staticAnalysisTool]' => 'phpstan',
            ]);

            $timeLimit = (int) ini_get('max_execution_time');
        } finally {
            set_time_limit(0);
        }

        static::assertSame(302, $client->getResponse()->getStatusCode());
        static::assertGreaterThan(Runner::getProcessTimeoutSec(StaticAnalysisTool::PHPStan), $timeLimit);
    }

    public function test_it_fails_with_when_code_is_blank(): void
    {
        $client = static::createClient();
        $client->followRedirects();
        $client->catchExceptions(false);

        $client->request('GET', '/');

        $client->submitForm('create_example[mutate]', [
            'create_example[code]' => '',
            'create_example[test]' => 'test',
            'create_example[config]' => '{"mutators": {"@default": true}}',
        ]);

        static::assertSame(200, $client->getResponse()->getStatusCode());
        static::assertStringContainsString('This value should not be blank', (string) $client->getResponse()->getContent());
    }

    public function test_it_fails_with_when_code_is_invalid(): void
    {
        $client = static::createClient();
        $client->followRedirects();
        $client->catchExceptions(false);

        $client->request('GET', '/');

        $client->submitForm('create_example[mutate]', [
            'create_example[code]' => '<?php $a ==== $b;',
            'create_example[test]' => 'test',
            'create_example[config]' => '{"mutators": {"@default": true}}',
        ]);

        static::assertSame(200, $client->getResponse()->getStatusCode());
        static::assertStringContainsString('This is not a valid PHP code. Errors: Syntax error, unexpected', (string) $client->getResponse()->getContent());
    }

    public function test_it_fails_with_when_test_is_invalid(): void
    {
        $client = static::createClient();
        $client->followRedirects();
        $client->catchExceptions(false);

        $client->request('GET', '/');

        $client->submitForm('create_example[mutate]', [
            'create_example[code]' => '<?php $a = $b;',
            'create_example[test]' => '<?php $a ==== $b;',
            'create_example[config]' => '{"mutators": {"@default": true}}',
        ]);

        static::assertSame(200, $client->getResponse()->getStatusCode());
        static::assertStringContainsString('This is not a valid PHP code. Errors: Syntax error, unexpected', (string) $client->getResponse()->getContent());
    }

    #[DataProvider('provideTooLongFields')]
    public function test_it_fails_when_a_field_is_too_long(string $field, string $value, int $limit): void
    {
        $client = static::createClient();
        $client->followRedirects();
        $client->catchExceptions(false);

        $client->request('GET', '/');

        $client->submitForm('create_example[mutate]', [
            'create_example[code]' => '<?php $a = $b;',
            'create_example[test]' => '<?php $a = $b;',
            'create_example[config]' => '{"mutators": {"@default": true}}',
            $field => $value,
        ]);

        static::assertSame(200, $client->getResponse()->getStatusCode());
        static::assertStringContainsString(
            sprintf('This value is too long. It should have %d characters or less.', $limit),
            (string) $client->getResponse()->getContent(),
        );
    }

    public static function provideTooLongFields(): Generator
    {
        $tooLongCode = '<?php //' . str_repeat('a', CreateExampleRequest::MAX_CODE_LENGTH);

        yield 'code' => ['create_example[code]', $tooLongCode, CreateExampleRequest::MAX_CODE_LENGTH];

        yield 'test' => ['create_example[test]', $tooLongCode, CreateExampleRequest::MAX_CODE_LENGTH];

        yield 'config' => [
            'create_example[config]',
            '{"mutators": {"@default": true}' . str_repeat(' ', CreateExampleRequest::MAX_CONFIG_LENGTH) . '}',
            CreateExampleRequest::MAX_CONFIG_LENGTH,
        ];
    }

    public function test_it_works_with_valid_both_code_and_test(): void
    {
        $client = static::createClient();
        $client->catchExceptions(false);

        $client->request('GET', '/');

        $client->submitForm('create_example[mutate]', [
            'create_example[code]' => '<?php $a = $b;',
            'create_example[test]' => '<?php $a = $b;',
            'create_example[config]' => '{"mutators": {"@default": true}}',
        ]);

        static::assertSame(302, $client->getResponse()->getStatusCode());
        static::assertStringNotContainsString('Error', (string) $client->getResponse()->getContent());
        static::assertStringNotContainsString('This value should be valid JSON', (string) $client->getResponse()->getContent());
        static::assertTrue($client->getResponse()->isRedirection());
    }

    /**
     * Guards the PHARs against the functions disabled on PHP level: a newer PHPUnit
     * calling one of them breaks every run.
     */
    public function test_it_runs_infection_and_kills_mutants(): void
    {
        $client = static::createClient();
        $client->catchExceptions(false);

        $client->followRedirects(true);
        $client->request('GET', '/');

        $client->submitForm('create_example[mutate]', [
            'create_example[code]' => <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace Infected;

                class SourceClass
                {
                    public function add(int $a, int $b): int
                    {
                        return $a + $b;
                    }
                }
                PHP . "\n// a new run, the newest in Explore: " . random_int(1, PHP_INT_MAX),
            'create_example[test]' => <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace Infected;

                use PHPUnit\Framework\TestCase;

                class SourceClassTest extends TestCase
                {
                    public function test_it_adds_2_numbers(): void
                    {
                        self::assertSame(3, (new SourceClass())->add(1, 2));
                    }
                }
                PHP,
            'create_example[config]' => '{"mutators": {"@default": true}}',
        ]);

        static::assertSame(200, $client->getResponse()->getStatusCode());
        static::assertStringContainsString('mutants were killed by Test Framework', (string) $client->getResponse()->getContent());

        // the editor shows the mutants on the submitted lines, `return $a + $b;` is on line 11
        $lineMapJson = $client->getCrawler()->filterXPath('//*[@id="line-map"]')->attr('data-map');
        static::assertIsString($lineMapJson);

        /** @var array<string, array<int, int>> $lineMap */
        $lineMap = json_decode($lineMapJson, true, 512, JSON_THROW_ON_ERROR);
        static::assertSame(11, $lineMap['SourceClass'][11] ?? null);

        // the numbers of the log are copied for Explore, which lists the run with them
        $client->request('GET', '/explore');
        static::assertStringContainsString('SourceClass::add()', $client->getCrawler()->filterXPath('//a[contains(@class, "run-card")]')->text());
    }

    public function test_it_runs_again_an_input_whose_run_never_finished(): void
    {
        $client = static::createClient();
        $client->catchExceptions(false);

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);
        /** @var Hashids $hashids */
        $hashids = static::getContainer()->get(Hashids::class);

        $code = <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace Infected;

            class SourceClass
            {
                public function add(int $a, int $b): int
                {
                    return $a + $b;
                }
            }
            PHP . "\n// " . random_int(1, PHP_INT_MAX);
        $test = <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace Infected;

            use PHPUnit\Framework\TestCase;

            class SourceClassTest extends TestCase
            {
                public function test_it_adds_2_numbers(): void
                {
                    self::assertSame(3, (new SourceClass())->add(1, 2));
                }
            }
            PHP;
        $config = '{"mutators": {"@default": true}}';

        // the request died before the result was saved, e.g. on a fatal error
        $unfinishedExample = new Example($code, $test, $config, Runner::CURRENT_INFECTION_VERSION, Runner::CURRENT_PHPUNIT_VERSION, Runner::CURRENT_PHP_VERSION);
        $em->persist($unfinishedExample);
        $em->flush();

        $client->request('GET', '/');
        $client->submitForm('create_example[mutate]', [
            'create_example[code]' => $code,
            'create_example[test]' => $test,
            'create_example[config]' => $config,
        ]);

        $idHash = $hashids->encode($unfinishedExample->getId());
        static::assertTrue($client->getResponse()->isRedirect('/r/' . $idHash));

        $client->followRedirect();
        static::assertStringContainsString('mutants were killed by Test Framework', (string) $client->getResponse()->getContent());

        $em->clear();
        $example = $em->find(Example::class, $unfinishedExample->getId());
        static::assertInstanceOf(Example::class, $example);
        static::assertSame($idHash, $example->getIdHash());
    }

    public function test_run_waits_for_a_change_on_a_run_with_the_current_versions(): void
    {
        $client = static::createClient();
        $client->catchExceptions(false);

        $client->request('GET', '/');
        static::assertCount(0, $client->getCrawler()->filterXPath('//*[@id="js-submit"][@data-run-on-current-versions]'));

        $client->followRedirects(true);
        $client->submitForm('create_example[mutate]', [
            'create_example[code]' => 'code ' . random_int(1, PHP_INT_MAX),
            'create_example[test]' => 'test',
            'create_example[config]' => '{"mutators": {"@default": true}}',
        ]);

        static::assertSame(200, $client->getResponse()->getStatusCode());
        static::assertCount(1, $client->getCrawler()->filterXPath('//*[@id="js-submit"][@data-run-on-current-versions]'));
    }

    /**
     * The versions are a part of a run: the same code, test and config make a new run on the current versions.
     */
    public function test_run_does_not_wait_for_a_change_on_a_run_with_older_versions(): void
    {
        $client = static::createClient();
        $client->catchExceptions(false);

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);
        /** @var Hashids $hashids */
        $hashids = static::getContainer()->get(Hashids::class);

        $example = new Example('code ' . random_int(1, PHP_INT_MAX), 'test', '{}', '0.26.0', Runner::CURRENT_PHPUNIT_VERSION, Runner::CURRENT_PHP_VERSION);
        $em->persist($example);
        $em->flush();
        $idHash = $hashids->encode($example->getId());
        $example->setIdHash($idHash);
        $em->flush();

        $client->request('GET', '/r/' . $idHash);

        static::assertSame(200, $client->getResponse()->getStatusCode());
        static::assertCount(1, $client->getCrawler()->filterXPath('//*[@id="js-submit"]'));
        static::assertCount(0, $client->getCrawler()->filterXPath('//*[@id="js-submit"][@data-run-on-current-versions]'));
    }

    public function test_it_runs_mago_on_the_escaped_mutants(): void
    {
        $client = static::createClient();
        $client->catchExceptions(false);

        $client->followRedirects(true);
        $client->request('GET', '/');

        $client->submitForm('create_example[mutate]', [
            'create_example[code]' => <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace Infected;

                class SourceClass
                {
                    /**
                     * @param array<int, int> $values
                     *
                     * @return list<int>
                     */
                    public function positive(array $values): array
                    {
                        return array_values(array_filter($values, fn (int $value): bool => $value > 0));
                    }
                }
                PHP,
            'create_example[test]' => <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace Infected;

                use PHPUnit\Framework\TestCase;

                class SourceClassTest extends TestCase
                {
                    public function test_it_keeps_the_positive_values(): void
                    {
                        self::assertCount(1, (new SourceClass())->positive([-1, 2]));
                    }
                }
                PHP,
            'create_example[config]' => '{"mutators": {"@default": true}}',
            'create_example[staticAnalysisTool]' => 'mago',
        ]);

        static::assertSame(200, $client->getResponse()->getStatusCode());
        static::assertStringContainsString('mutants were caught by Static Analysis', (string) $client->getResponse()->getContent());

        $crawler = $client->getCrawler();

        /** @var array{killedByStaticAnalysis: list<array{mutator: array{mutatorName: string}}>} $jsonLog */
        $jsonLog = json_decode((string) $crawler->filterXPath('//*[@id="json-log"]')->attr('data-log'), true, 512, JSON_THROW_ON_ERROR);
        // the test does not check the keys, Mago sees that the result is not a list<int> anymore
        static::assertSame(['UnwrapArrayValues'], array_map(
            static fn (array $mutant): string => $mutant['mutator']['mutatorName'],
            $jsonLog['killedByStaticAnalysis'],
        ));

        static::assertSame('Mago', $crawler->filterXPath('//*[@id="json-log"]')->attr('data-static-analysis-tool'));
        static::assertSame('mago', $crawler->filterXPath('//input[@name="create_example[staticAnalysisTool]"][@checked]')->attr('value'));
        static::assertSame(
            'Mago ' . Runner::CURRENT_MAGO_VERSION,
            $crawler->filterXPath('//*[@data-static-analysis-chip="mago"][not(@hidden)]')->text(),
        );

        $idHash = basename($client->getRequest()->getUri());

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);
        /** @var Hashids $hashids */
        $hashids = static::getContainer()->get(Hashids::class);

        $example = $em->find(Example::class, $hashids->decode($idHash)[0]);
        static::assertInstanceOf(Example::class, $example);
        static::assertSame(StaticAnalysisTool::Mago, $example->getStaticAnalysisTool());
        static::assertSame(Runner::CURRENT_MAGO_VERSION, $example->getStaticAnalysisToolVersion());

        $phpVersion = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;

        static::assertSame(
            <<<TOML
                php-version = "{$phpVersion}"

                [source]
                paths = ["src"]
                TOML,
            file_get_contents(sprintf('%s/../../../infection-builds/%s/mago.toml', __DIR__, $idHash)),
        );
    }

    public function test_it_runs_phpstan_on_the_escaped_mutants(): void
    {
        $client = static::createClient();
        $client->catchExceptions(false);

        $client->followRedirects(true);
        $client->request('GET', '/');

        $client->submitForm('create_example[mutate]', [
            'create_example[code]' => <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace Infected;

                class SourceClass
                {
                    /**
                     * @param array<int, int> $values
                     *
                     * @return list<int>
                     */
                    public function positive(array $values): array
                    {
                        return array_values(array_filter($values, fn (int $value): bool => $value > 0));
                    }
                }
                PHP,
            'create_example[test]' => <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace Infected;

                use PHPUnit\Framework\TestCase;

                class SourceClassTest extends TestCase
                {
                    public function test_it_keeps_the_positive_values(): void
                    {
                        self::assertCount(1, (new SourceClass())->positive([-1, 2]));
                    }
                }
                PHP,
            'create_example[config]' => '{"mutators": {"@default": true}}',
            'create_example[staticAnalysisTool]' => 'phpstan',
        ]);

        static::assertSame(200, $client->getResponse()->getStatusCode());
        static::assertStringContainsString('mutants were caught by Static Analysis', (string) $client->getResponse()->getContent());

        $crawler = $client->getCrawler();

        /** @var array{killedByStaticAnalysis: list<array{mutator: array{mutatorName: string}}>} $jsonLog */
        $jsonLog = json_decode((string) $crawler->filterXPath('//*[@id="json-log"]')->attr('data-log'), true, 512, JSON_THROW_ON_ERROR);
        // the test does not check the keys, PHPStan sees that the result is not a list<int> anymore
        static::assertSame(['UnwrapArrayValues'], array_map(
            static fn (array $mutant): string => $mutant['mutator']['mutatorName'],
            $jsonLog['killedByStaticAnalysis'],
        ));

        static::assertSame('PHPStan', $crawler->filterXPath('//*[@id="json-log"]')->attr('data-static-analysis-tool'));
        static::assertSame('phpstan', $crawler->filterXPath('//input[@name="create_example[staticAnalysisTool]"][@checked]')->attr('value'));
        static::assertSame(
            'PHPStan ' . Runner::CURRENT_PHPSTAN_VERSION,
            $crawler->filterXPath('//*[@data-static-analysis-chip="phpstan"][not(@hidden)]')->text(),
        );

        $idHash = basename($client->getRequest()->getUri());

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);
        /** @var Hashids $hashids */
        $hashids = static::getContainer()->get(Hashids::class);

        $example = $em->find(Example::class, $hashids->decode($idHash)[0]);
        static::assertInstanceOf(Example::class, $example);
        static::assertSame(StaticAnalysisTool::PHPStan, $example->getStaticAnalysisTool());
        static::assertSame(Runner::CURRENT_PHPSTAN_VERSION, $example->getStaticAnalysisToolVersion());

        static::assertSame(
            <<<'NEON'
                parameters:
                    level: max
                    paths:
                        - src
                    tmpDir: var/phpstan
                    parallel:
                        maximumNumberOfProcesses: 1
                NEON,
            file_get_contents(sprintf('%s/../../../infection-builds/%s/phpstan.neon', __DIR__, $idHash)),
        );
    }

    public function test_a_new_run_does_not_use_static_analysis_by_default(): void
    {
        $client = static::createClient();
        $client->catchExceptions(false);

        $crawler = $client->request('GET', '/');

        static::assertSame('', $crawler->filterXPath('//input[@name="create_example[staticAnalysisTool]"][@checked]')->attr('value'));
        static::assertCount(0, $crawler->filterXPath('//*[@data-static-analysis-chip][not(@hidden)]'));
    }

    /**
     * Mago is a part of a run like the versions: an older Mago makes a new run with the same code, test and config.
     */
    #[DataProvider('provideMagoVersions')]
    public function test_run_waits_for_a_change_on_a_run_with_the_current_mago(string $magoVersion, bool $waitsForAChange): void
    {
        $client = static::createClient();
        $client->catchExceptions(false);

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);
        /** @var Hashids $hashids */
        $hashids = static::getContainer()->get(Hashids::class);

        $example = new Example('code ' . random_int(1, PHP_INT_MAX), 'test', '{}', Runner::CURRENT_INFECTION_VERSION, Runner::CURRENT_PHPUNIT_VERSION, Runner::CURRENT_PHP_VERSION, StaticAnalysisTool::Mago, $magoVersion);
        $em->persist($example);
        $em->flush();
        $idHash = $hashids->encode($example->getId());
        $example->setIdHash($idHash);
        $em->flush();

        $crawler = $client->request('GET', '/r/' . $idHash);

        static::assertSame(200, $client->getResponse()->getStatusCode());
        static::assertCount($waitsForAChange ? 1 : 0, $crawler->filterXPath('//*[@id="js-submit"][@data-run-on-current-versions]'));
        // the top bar shows the Mago of the run
        static::assertSame('Mago ' . $magoVersion, $crawler->filterXPath('//*[@data-static-analysis-chip="mago"][not(@hidden)]')->text());
    }

    public static function provideMagoVersions(): Generator
    {
        yield 'current Mago' => [Runner::CURRENT_MAGO_VERSION, true];

        yield 'older Mago' => ['1.23.0', false];
    }

    public function test_it_fails_when_the_static_analysis_tool_is_unknown(): void
    {
        $client = static::createClient();
        $client->followRedirects();
        $client->catchExceptions(false);

        $crawler = $client->request('GET', '/');

        $form = $crawler->selectButton('create_example[mutate]')->form();
        $form->disableValidation();
        $form->setValues([
            'create_example[code]' => '<?php $a = $b;',
            'create_example[test]' => '<?php $a = $b;',
            'create_example[config]' => '{"mutators": {"@default": true}}',
            'create_example[staticAnalysisTool]' => 'psalm',
        ]);
        $crawler = $client->submit($form);

        static::assertSame(200, $client->getResponse()->getStatusCode());
        static::assertStringContainsString('Static analysis', $crawler->filterXPath('//ul[@class="errors"]')->text());
    }

    public function test_it_fails_when_config_is_not_a_valid_json(): void
    {
        $client = static::createClient();
        $client->followRedirects();
        $client->catchExceptions(false);

        $client->request('GET', '/');

        $client->submitForm('create_example[mutate]', [
            'create_example[code]' => '<?php $a = $b;',
            'create_example[test]' => '<?php $a = $b;',
            'create_example[config]' => '{...',
        ]);

        static::assertSame(200, $client->getResponse()->getStatusCode());
        static::assertStringNotContainsString('Error', (string) $client->getResponse()->getContent());
        static::assertStringContainsString('This value should be valid JSON', (string) $client->getResponse()->getContent());
    }

    public function test_it_fails_when_config_contains_not_allowed_property(): void
    {
        $client = static::createClient();
        $client->catchExceptions(false);

        $client->request('GET', '/');

        $client->submitForm('create_example[mutate]', [
            'create_example[code]' => '<?php $a = $b;',
            'create_example[test]' => '<?php $a = $b;',
            'create_example[config]' => '{"bootstrap": "", "source": {"directories": ["src"]}}',
        ]);

        static::assertSame(200, $client->getResponse()->getStatusCode());
        static::assertStringNotContainsString('Error', (string) $client->getResponse()->getContent());
        static::assertStringContainsString('The property bootstrap is not defined and the definition does not allow additional properties', (string) $client->getResponse()->getContent());
        static::assertFalse($client->getResponse()->isRedirect());
    }

    #[DataProvider('provideForbiddenFunctions')]
    public function test_phpunit_test_case_fails_if_forbidden_function_is_used(string $exploitFunction): void
    {
        $client = static::createClient();
        $client->catchExceptions(false);

        $client->followRedirects(true);
        $client->request('GET', '/');

        $client->submitForm('create_example[mutate]', [
            'create_example[code]' => sprintf(
                <<<'PHP'
                <?php
                
                declare(strict_types=1);
                
                namespace Infected;
                
                class SourceClass
                {
                    public function add(int $a, int $b): int
                    {
                        $system = '%s';
                        $system('pwd'); // exploit!
                        return $a + $b;
                    }
                }
                PHP,
                $exploitFunction
            ),
            'create_example[test]' => <<<'PHP'
            <?php
            
            declare(strict_types=1);
            
            namespace Infected;
            
            use Infected\SourceClass;
            use PHPUnit\Framework\TestCase;
            
            class SourceClassTest extends TestCase
            {
                public function test_it_adds_2_numbers(): void
                {
                    $source = new SourceClass();
            
                    $result = $source->add(1, 2);
            
                    self::assertSame(3, $result);
                }
            }
            PHP,
            'create_example[config]' => '{"mutators": {"@default": true}}',
        ]);

        static::assertSame(200, $client->getResponse()->getStatusCode());
        static::assertStringContainsString(
            sprintf('Error: Call to undefined function %s()', $exploitFunction),
            (string) $client->getResponse()->getContent()
        );
    }

    public function test_the_code_does_not_see_the_environment_of_the_app(): void
    {
        $client = static::createClient();
        $client->catchExceptions(false);

        $client->followRedirects(true);
        $client->request('GET', '/');

        $client->submitForm('create_example[mutate]', [
            'create_example[code]' => <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace Infected;

                class SourceClass
                {
                    public function add(int $a, int $b): int
                    {
                        return $a + $b;
                    }
                }
                PHP,
            'create_example[test]' => <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace Infected;

                use PHPUnit\Framework\TestCase;

                class SourceClassTest extends TestCase
                {
                    public function test_it_reads_the_environment(): void
                    {
                        self::fail(sprintf(
                            'Environment: DATABASE_URL=%s APP_SECRET=%s',
                            $_SERVER['DATABASE_URL'] ?? 'absent',
                            $_SERVER['APP_SECRET'] ?? 'absent',
                        ));
                    }
                }
                PHP,
            'create_example[config]' => '{"mutators": {"@default": true}}',
        ]);

        static::assertSame(200, $client->getResponse()->getStatusCode());
        static::assertStringContainsString(
            'Environment: DATABASE_URL=absent APP_SECRET=absent',
            (string) $client->getResponse()->getContent()
        );
    }

    public static function provideForbiddenFunctions(): Generator
    {
        yield 'exec' => ['exec'];

        yield 'shell_exec' => ['shell_exec'];

        yield 'system' => ['system'];
    }
}
