<?php

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
        self::assertSame(200, $client->getResponse()->getStatusCode());

        $client->submitForm('create_example[mutate]', [
            'create_example[code]' => 'code',
            'create_example[test]' => 'test',
            'create_example[config]' => '{"mutators": {"@default": true}}',
        ]);

        self::assertSame(302, $client->getResponse()->getStatusCode());
        self::assertStringNotContainsString('This value should not be blank', (string) $client->getResponse()->getContent());
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

        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertStringContainsString('This value should not be blank', (string) $client->getResponse()->getContent());
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

        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertStringContainsString('This is not a valid PHP code. Errors: Syntax error, unexpected', (string) $client->getResponse()->getContent());
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

        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertStringContainsString('This is not a valid PHP code. Errors: Syntax error, unexpected', (string) $client->getResponse()->getContent());
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

        self::assertSame(302, $client->getResponse()->getStatusCode());
        self::assertStringNotContainsString('Error', (string) $client->getResponse()->getContent());
        self::assertStringNotContainsString('This value should be valid JSON', (string) $client->getResponse()->getContent());
        self::assertTrue($client->getResponse()->isRedirection());
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

        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertStringNotContainsString('Error', (string) $client->getResponse()->getContent());
        self::assertStringContainsString('This value should be valid JSON', (string) $client->getResponse()->getContent());
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

        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertStringNotContainsString('Error', (string) $client->getResponse()->getContent());
        self::assertStringContainsString('The property bootstrap is not defined and the definition does not allow additional properties', (string) $client->getResponse()->getContent());
        self::assertFalse($client->getResponse()->isRedirect());
    }
}
