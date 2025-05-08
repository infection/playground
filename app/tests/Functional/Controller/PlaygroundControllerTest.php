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

    public function test_phpunit_test_case_fails_if_forbidden_function_is_used(): void
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
                    $system = 'system';
                    $system('pwd'); // exploit!

                    return $a + $b;
                }
            }
            PHP,
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
        static::assertStringContainsString('Error: Call to undefined function system() ', (string) $client->getResponse()->getContent());
    }
}
