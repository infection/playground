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

use App\Entity\AstRun;
use Doctrine\ORM\EntityManagerInterface;
use Hashids\Hashids;
use function json_decode;
use function json_encode;
use const PHP_INT_MAX;
use function random_int;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class AstControllerTest extends WebTestCase
{
    private const OLD_EXAMPLE = "<?php\nfunction add(int \$a, int \$b): int\n{\n    return \$a + \$b;\n}";

    public function test_it_renders_the_explorer(): void
    {
        $client = self::createExplorerClient();

        $client->request('GET', '/ast');

        static::assertSame(200, $client->getResponse()->getStatusCode());

        $initial = self::initialData($client);
        static::assertNull($initial['code']);
        static::assertNull($initial['xray']);
    }

    public function test_it_returns_nodes_with_utf16_offsets(): void
    {
        $client = self::createExplorerClient();

        $nodes = self::postXRay($client, '<?php echo "é", $a;');

        static::assertSame(200, $client->getResponse()->getStatusCode());
        static::assertSame(
            [
                ['Stmt\Echo_', 6, 19, -1, [['exprs', [1, 2]]], 'PhpParser\Node\Stmt\Echo_'],
                ['Scalar\String_', 11, 14, 0, [['value', '"é"']], 'PhpParser\Node\Scalar\String_'],
                ['Expr\Variable', 16, 18, 0, [['name', '"a"']], 'PhpParser\Node\Stmt\Echo_'],
            ],
            $nodes,
        );
    }

    public function test_it_describes_flags_child_nodes_and_resolved_names(): void
    {
        $client = self::createExplorerClient();

        $nodes = self::postXRay($client, '<?php namespace N; class A extends B { public static function f(int $x) {} }');

        static::assertSame('Stmt\Namespace_', $nodes[0][0]);
        static::assertSame('Name', $nodes[1][0]);
        static::assertSame('Stmt\Class_', $nodes[2][0]);
        static::assertSame('Name\FullyQualified', $nodes[4][0]);
        static::assertContains(['name', '"N\\\\B"'], $nodes[4][4]);

        $method = $nodes[5];

        static::assertSame('Stmt\ClassMethod', $method[0]);
        static::assertSame(2, $method[3]);
        static::assertContains(['flags', 'MODIFIER_PUBLIC | MODIFIER_STATIC (9)'], $method[4]);
        static::assertContains(['params', [7]], $method[4]);
        static::assertSame('Param', $nodes[7][0]);
    }

    public function test_it_returns_parse_errors(): void
    {
        $client = self::createExplorerClient();

        $response = self::postJson($client, '/ast/xray', ['code' => '<?php $a ==== $b;']);

        static::assertSame(422, $client->getResponse()->getStatusCode());
        static::assertSame(
            ['error' => ['message' => "Syntax error, unexpected '='", 'startLine' => 1, 'startColumn' => 13, 'endColumn' => 13]],
            $response,
        );
    }

    public function test_it_rejects_invalid_payload(): void
    {
        $client = self::createExplorerClient();

        $client->request('POST', '/ast/xray', [], [], ['CONTENT_TYPE' => 'application/json'], '{"foo": 1}');

        static::assertSame(400, $client->getResponse()->getStatusCode());
    }

    public function test_it_shares_code_once_per_content(): void
    {
        $client = self::createExplorerClient();
        $code = '<?php $shared = ' . random_int(1, PHP_INT_MAX) . ';';

        $first = self::share($client, $code);
        static::assertSame(200, $client->getResponse()->getStatusCode());
        static::assertSame($code, $first['code']);
        static::assertSame('/ast/' . $first['idHash'], $first['url']);

        $second = self::share($client, $code);
        static::assertSame($first['idHash'], $second['idHash']);
    }

    public function test_it_adds_the_opening_tag_when_sharing(): void
    {
        $client = self::createExplorerClient();
        $code = '$noTag = ' . random_int(1, PHP_INT_MAX) . ';';

        $response = self::postJson($client, '/ast/share', ['code' => $code]);

        static::assertSame('<?php ' . $code, $response['code']);
    }

    public function test_it_does_not_share_blank_or_invalid_code(): void
    {
        $client = self::createExplorerClient();

        self::postJson($client, '/ast/share', ['code' => '  ']);
        static::assertSame(400, $client->getResponse()->getStatusCode());

        self::postJson($client, '/ast/share', ['code' => '<?php $a ==== $b;']);
        static::assertSame(422, $client->getResponse()->getStatusCode());
    }

    /**
     * Links of the original AST explorer are /ast/{hash}/{nodeId}, where nodeId is the 1-based
     * pre-order index of the node. They must pin the very same node.
     */
    public function test_old_links_pin_the_same_node(): void
    {
        $client = self::createExplorerClient();
        $idHash = self::share($client, self::OLD_EXAMPLE)['idHash'];

        // node 13 of this example is "$b" in "return $a + $b;" in the original explorer
        $client->request('GET', '/ast/' . $idHash . '/13');

        static::assertSame(200, $client->getResponse()->getStatusCode());

        $initial = self::initialData($client);
        static::assertSame(self::OLD_EXAMPLE, $initial['code']);
        static::assertSame($idHash, $initial['idHash']);
        static::assertSame(13, $initial['activeNodeId']);

        $nodes = self::initialNodes($client);
        $node = $nodes[13 - 1];
        static::assertSame('Expr\Variable', $node[0]);
        static::assertSame([['name', '"b"']], $node[4]);
        static::assertSame('PhpParser\Node\Expr\BinaryOp\Plus', $node[5]);
        static::assertSame('Expr\BinaryOp\Plus', $nodes[$node[3]][0]);
    }

    /**
     * The original AST explorer stored code as typed, without adding the opening tag.
     */
    public function test_old_links_to_code_without_an_opening_tag_pin_the_same_node(): void
    {
        $client = self::createExplorerClient();
        $code = "function add(int \$a, int \$b): int\n{\n    return \$a + \$b;\n}\n// " . random_int(1, PHP_INT_MAX);

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);
        /** @var Hashids $hashids */
        $hashids = static::getContainer()->get(Hashids::class);

        $astRun = new AstRun($code);
        $em->persist($astRun);
        $em->flush();
        $idHash = $hashids->encode($astRun->getId());
        $astRun->setIdHash($idHash);
        $em->flush();

        // node 13 of this example is "$b" in "return $a + $b;" in the original explorer
        $client->request('GET', '/ast/' . $idHash . '/13');

        static::assertSame(200, $client->getResponse()->getStatusCode());
        static::assertSame('<?php ' . $code, self::initialData($client)['code']);

        $node = self::initialNodes($client)[13 - 1];
        static::assertSame('Expr\Variable', $node[0]);
        static::assertSame([['name', '"b"']], $node[4]);
    }

    public function test_shared_code_can_not_break_out_of_the_initial_data(): void
    {
        $client = self::createExplorerClient();
        $code = "<?php echo '<!--<script></script>&amp;'; // " . random_int(1, PHP_INT_MAX);
        $idHash = self::share($client, $code)['idHash'];

        $client->request('GET', '/ast/' . $idHash);

        $html = (string) $client->getResponse()->getContent();
        static::assertStringNotContainsString('<!--<script>', $html);
        static::assertSame($code, self::initialData($client)['code']);
    }

    public function test_it_shows_shared_code_without_a_pinned_node(): void
    {
        $client = self::createExplorerClient();
        $idHash = self::share($client, self::OLD_EXAMPLE)['idHash'];

        $client->request('GET', '/ast/' . $idHash);

        static::assertSame(200, $client->getResponse()->getStatusCode());
        static::assertNull(self::initialData($client)['activeNodeId']);
    }

    public function test_it_returns_404_for_unknown_links(): void
    {
        $client = self::createExplorerClient();

        $client->request('GET', '/ast/unknown-hash/1');

        static::assertSame(404, $client->getResponse()->getStatusCode());
    }

    private static function createExplorerClient(): KernelBrowser
    {
        self::ensureKernelShutdown();

        return static::createClient();
    }

    /**
     * @return array{idHash: string, code: string, url: string}
     */
    private static function share(KernelBrowser $client, string $code): array
    {
        /** @var array{idHash: string, code: string, url: string} $data */
        $data = self::postJson($client, '/ast/share', ['code' => $code]);

        return $data;
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private static function postJson(KernelBrowser $client, string $url, array $payload): array
    {
        $client->request('POST', $url, [], [], ['CONTENT_TYPE' => 'application/json'], (string) json_encode($payload));

        /** @var array<string, mixed> $data */
        $data = json_decode((string) $client->getResponse()->getContent(), true);

        return $data;
    }

    /**
     * @return list<array{string, int, int, int, list<array{string, mixed}>, string}>
     */
    private static function postXRay(KernelBrowser $client, string $code): array
    {
        /** @var array{nodes: list<array{string, int, int, int, list<array{string, mixed}>, string}>} $xray */
        $xray = self::postJson($client, '/ast/xray', ['code' => $code]);

        return $xray['nodes'];
    }

    /**
     * @return array{code: string|null, idHash: string|null, activeNodeId: int|null, xray: array<string, mixed>|null}
     */
    private static function initialData(KernelBrowser $client): array
    {
        /** @var array{code: string|null, idHash: string|null, activeNodeId: int|null, xray: array<string, mixed>|null} $data */
        $data = json_decode($client->getCrawler()->filterXPath('//script[@id="ast-initial"]')->text(null, false), true);

        return $data;
    }

    /**
     * @return list<array{string, int, int, int, list<array{string, mixed}>, string}>
     */
    private static function initialNodes(KernelBrowser $client): array
    {
        /** @var array{nodes: list<array{string, int, int, int, list<array{string, mixed}>, string}>} $xray */
        $xray = self::initialData($client)['xray'];

        return $xray['nodes'];
    }
}
