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

use function json_decode;
use function json_encode;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class AstXRayControllerTest extends WebTestCase
{
    public function test_it_renders_the_page(): void
    {
        $client = static::createClient();
        $client->catchExceptions(false);

        $client->request('GET', '/ast2');

        static::assertSame(200, $client->getResponse()->getStatusCode());
        static::assertStringContainsString('editor-code-ast2', (string) $client->getResponse()->getContent());
    }

    public function test_it_returns_nodes_with_utf16_offsets(): void
    {
        $client = static::createClient();
        $client->catchExceptions(false);

        $client->request('POST', '/ast2/xray', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode(['code' => '<?php echo "é", $a;']));

        static::assertSame(200, $client->getResponse()->getStatusCode());

        $nodes = json_decode((string) $client->getResponse()->getContent(), true)['nodes'];

        static::assertSame(
            [
                ['Stmt\Echo_', 6, 19, -1, [['exprs', [1, 2]]], 'PhpParser\Node\Stmt\Echo_'],
                ['Scalar\String_', 11, 14, 0, [['value', '"é"']], 'PhpParser\Node\Scalar\String_'],
                ['Expr\Variable', 16, 18, 0, [['name', '"a"']], 'PhpParser\Node\Stmt\Echo_'],
            ],
            $nodes,
        );
    }

    public function test_it_describes_flags_and_child_nodes(): void
    {
        $client = static::createClient();
        $client->catchExceptions(false);

        $client->request('POST', '/ast2/xray', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode(['code' => '<?php class A { public static function f(int $x) {} }']));

        $nodes = json_decode((string) $client->getResponse()->getContent(), true)['nodes'];
        $method = $nodes[2];

        static::assertSame('Stmt\ClassMethod', $method[0]);
        static::assertSame(0, $method[3]);
        static::assertContains(['flags', 'MODIFIER_PUBLIC | MODIFIER_STATIC (9)'], $method[4]);
        static::assertContains(['params', [4]], $method[4]);
        static::assertSame('Param', $nodes[4][0]);
    }

    public function test_it_returns_parse_errors(): void
    {
        $client = static::createClient();
        $client->catchExceptions(false);

        $client->request('POST', '/ast2/xray', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode(['code' => '<?php $a ==== $b;']));

        static::assertSame(422, $client->getResponse()->getStatusCode());
        static::assertSame(
            ['error' => ['message' => "Syntax error, unexpected '='", 'startLine' => 1, 'startColumn' => 13, 'endColumn' => 13]],
            json_decode((string) $client->getResponse()->getContent(), true),
        );
    }

    public function test_it_rejects_invalid_payload(): void
    {
        $client = static::createClient();
        $client->catchExceptions(false);

        $client->request('POST', '/ast2/xray', [], [], ['CONTENT_TYPE' => 'application/json'], '{"foo": 1}');

        static::assertSame(400, $client->getResponse()->getStatusCode());
    }
}
