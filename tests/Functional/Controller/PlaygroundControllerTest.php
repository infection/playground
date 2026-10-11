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

use App\Request\CreateExampleRequest;
use Generator;
use function json_decode;
use const JSON_THROW_ON_ERROR;
use PHPUnit\Framework\Attributes\DataProvider;
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
                PHP,
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
            'create_example[title]' => 'Kills every mutant',
        ]);

        static::assertSame(200, $client->getResponse()->getStatusCode());
        static::assertStringContainsString('mutants were killed by Test Framework', (string) $client->getResponse()->getContent());

        // the editor shows the mutants on the submitted lines, `return $a + $b;` is on line 11
        $lineMapJson = $client->getCrawler()->filterXPath('//*[@id="line-map"]')->attr('data-map');
        static::assertIsString($lineMapJson);

        /** @var array<string, array<int, int>> $lineMap */
        $lineMap = json_decode($lineMapJson, true, 512, JSON_THROW_ON_ERROR);
        static::assertSame(11, $lineMap['SourceClass'][11] ?? null);

        // the numbers of the log are copied for Explore: a listed run with mutants can be reported from it
        static::assertCount(1, $client->getCrawler()->filterXPath('//form[contains(@action, "/report")]'));
        static::assertSame('Kills every mutant', $client->getCrawler()->filterXPath('//*[@id="create_example_title"]')->attr('value'));
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
