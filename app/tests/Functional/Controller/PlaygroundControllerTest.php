<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class PlaygroundControllerTest extends WebTestCase
{
    public function test_it_creates_a_mutation_example(): void
    {
        $client = static::createClient();
        $client->followRedirects();
        $client->catchExceptions(false);

        $client->request('GET', '/');
        self::assertSame(200, $client->getResponse()->getStatusCode());

        $client->submitForm('create_example[mutate]', [
            'create_example[code]' => 'code',
            'create_example[test]' => 'test',
            'create_example[config]' => 'config',
        ]);

        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertNotContains('This value should not be blank', $client->getResponse()->getContent());
    }

    public function test_it_fails_with_validation_errors(): void
    {
        $client = static::createClient();
        $client->followRedirects();
        $client->catchExceptions(false);

        $client->request('GET', '/');

        $client->submitForm('create_example[mutate]', [
            'create_example[code]' => '',
            'create_example[test]' => 'test',
            'create_example[config]' => 'config',
        ]);

        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertContains('This value should not be blank', $client->getResponse()->getContent());
    }
}
