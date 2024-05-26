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

class AstControllerTest extends WebTestCase
{
    public function test_it_creates_an_ast_run(): void
    {
        $client = static::createClient();
        $client->catchExceptions(false);

        $client->request('GET', '/ast');
        static::assertSame(200, $client->getResponse()->getStatusCode());

        $client->submitForm('create_ast_run[buildAst]', [
            'create_ast_run[code]' => 'code',
        ]);

        static::assertSame(302, $client->getResponse()->getStatusCode());
        static::assertStringNotContainsString('This value should not be blank', (string) $client->getResponse()->getContent());
    }

    public function test_it_fails_with_when_code_is_blank(): void
    {
        $client = static::createClient();
        $client->followRedirects();
        $client->catchExceptions(false);

        $client->request('GET', '/ast');

        $client->submitForm('create_ast_run[buildAst]', [
            'create_ast_run[code]' => '',
        ]);

        static::assertSame(200, $client->getResponse()->getStatusCode());
        static::assertStringContainsString('This value should not be blank', (string) $client->getResponse()->getContent());
    }

    public function test_it_fails_with_when_code_is_invalid(): void
    {
        $client = static::createClient();
        $client->followRedirects();
        $client->catchExceptions(false);

        $client->request('GET', '/ast');

        $client->submitForm('create_ast_run[buildAst]', [
            'create_ast_run[code]' => '<?php $a ==== $b;',
        ]);

        static::assertSame(200, $client->getResponse()->getStatusCode());
        static::assertStringContainsString('This is not a valid PHP code. Errors: Syntax error, unexpected', (string) $client->getResponse()->getContent());
    }
}
