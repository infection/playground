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

namespace App\PhpParser\XRay;

use function array_map;
use function array_values;
use function count;
use function get_debug_type;
use function implode;
use function is_array;
use function is_bool;
use function is_float;
use function is_int;
use function is_string;
use function json_encode;
use const JSON_INVALID_UTF8_SUBSTITUTE;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;
use function mb_strlen;
use function mb_substr;
use function ord;
use PhpParser\Error;
use PhpParser\Lexer\Emulative;
use PhpParser\Node;
use PhpParser\Node\Stmt\Class_;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\ParentConnectingVisitor;
use PhpParser\Parser;
use PhpParser\ParserFactory;
use function preg_match;
use function spl_object_id;
use function str_replace;
use function strlen;

/**
 * Builds the data for the AST X-Ray: a flat pre-order list of nodes with their source ranges.
 *
 * Inspired by PHPStan's playground AST X-Ray (https://github.com/phpstan/phpstan, MIT license).
 *
 * Every node is [kind, from, to, parent, props, mutatorTarget]:
 *  - kind is the class name without "PhpParser\Node\"
 *  - from/to are UTF-16 offsets (what JS editors count in), "to" is exclusive
 *  - parent is the index of the parent node, -1 for top level statements
 *  - props is a list of [subNodeName, value], where value is a child node index (int),
 *    a list of those, or a rendered scalar (string)
 *  - mutatorTarget is the node class to use in Mutator::canMutate() for this node
 */
final class XRayBuilder
{
    private const MAX_SCALAR_LENGTH = 60;

    private const MODIFIERS = [
        Class_::MODIFIER_PUBLIC => 'MODIFIER_PUBLIC',
        Class_::MODIFIER_PROTECTED => 'MODIFIER_PROTECTED',
        Class_::MODIFIER_PRIVATE => 'MODIFIER_PRIVATE',
        Class_::MODIFIER_STATIC => 'MODIFIER_STATIC',
        Class_::MODIFIER_ABSTRACT => 'MODIFIER_ABSTRACT',
        Class_::MODIFIER_FINAL => 'MODIFIER_FINAL',
        Class_::MODIFIER_READONLY => 'MODIFIER_READONLY',
    ];

    private Parser $parser;

    /**
     * @var list<array{string, int, int, int, list<array{string, int|string|list<int|string>}>, string}>
     */
    private array $nodes = [];

    /**
     * @var array<int, int> spl_object_id => node index
     */
    private array $indexByObjectId = [];

    public function __construct(
        private MutatorTargetNodeResolver $mutatorTargetNodeResolver,
    ) {
        $lexer = new Emulative([
            'usedAttributes' => ['comments', 'startLine', 'endLine', 'startFilePos', 'endFilePos'],
        ]);

        $this->parser = (new ParserFactory())->create(ParserFactory::PREFER_PHP7, $lexer);
    }

    /**
     * @throws Error when the code can not be parsed
     *
     * @return array{nodes: list<array{string, int, int, int, list<array{string, int|string|list<int|string>}>, string}>}
     */
    public function build(string $code): array
    {
        $stmts = $this->parser->parse($code) ?? [];

        $traverser = new NodeTraverser();
        $traverser->addVisitor(new ParentConnectingVisitor());
        $traverser->traverse($stmts);

        $this->nodes = [];
        $this->indexByObjectId = [];

        foreach ($stmts as $stmt) {
            $this->visit($stmt, -1);
        }

        $toUtf16 = $this->createUtf16OffsetMapper($code);

        $nodes = [];

        foreach ($this->nodes as [$kind, $from, $to, $parent, $props, $target]) {
            $nodes[] = [$kind, $toUtf16($from), $toUtf16($to), $parent, $props, $target];
        }

        return ['nodes' => $nodes];
    }

    private function visit(Node $node, int $parent): ?int
    {
        if (!$node->hasAttribute('startFilePos') || !$node->hasAttribute('endFilePos')) {
            return null;
        }

        $index = count($this->nodes);
        $this->indexByObjectId[spl_object_id($node)] = $index;
        $this->nodes[] = [
            self::kind($node),
            $node->getStartFilePos(),
            $node->getEndFilePos() + 1,
            $parent,
            [],
            $this->mutatorTargetNodeResolver->resolve($node),
        ];

        // pre-order: children get their indexes before the props of this node are described
        foreach ($node->getSubNodeNames() as $name) {
            $value = $node->{$name};

            if ($value instanceof Node) {
                $this->visit($value, $index);
            } elseif (is_array($value)) {
                foreach ($value as $item) {
                    if ($item instanceof Node) {
                        $this->visit($item, $index);
                    }
                }
            }
        }

        $props = [];

        foreach ($node->getSubNodeNames() as $name) {
            $props[] = [$name, $this->describe($name, $node->{$name})];
        }

        $this->nodes[$index][4] = $props;

        return $index;
    }

    /**
     * @return int|string|list<int|string>
     */
    private function describe(string $name, mixed $value): int|string|array
    {
        if ($value instanceof Node) {
            return $this->indexByObjectId[spl_object_id($value)] ?? self::kind($value);
        }

        if (is_array($value)) {
            return array_map(
                fn (mixed $item): int|string => $item instanceof Node
                    ? ($this->indexByObjectId[spl_object_id($item)] ?? self::kind($item))
                    : $this->scalar($item),
                array_values($value),
            );
        }

        if ($name === 'flags' && is_int($value)) {
            return $this->flags($value);
        }

        return $this->scalar($value);
    }

    private function scalar(mixed $value): string
    {
        if ($value === null) {
            return 'null';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        if (is_string($value)) {
            $encoded = (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);

            return mb_strlen($encoded) > self::MAX_SCALAR_LENGTH
                ? mb_substr($encoded, 0, self::MAX_SCALAR_LENGTH) . '…'
                : $encoded;
        }

        return get_debug_type($value);
    }

    private function flags(int $flags): string
    {
        $names = [];

        foreach (self::MODIFIERS as $modifier => $name) {
            if (($flags & $modifier) !== 0) {
                $names[] = $name;
            }
        }

        return $names === [] ? (string) $flags : implode(' | ', $names) . ' (' . $flags . ')';
    }

    private static function kind(Node $node): string
    {
        return str_replace('PhpParser\\Node\\', '', $node::class);
    }

    /**
     * php-parser gives byte offsets, JS editors count UTF-16 code units.
     *
     * @return callable(int): int
     */
    private function createUtf16OffsetMapper(string $code): callable
    {
        if (preg_match('/[^\x00-\x7F]/', $code) !== 1) {
            return static fn (int $offset): int => $offset;
        }

        $map = [];
        $units = 0;
        $length = strlen($code);

        for ($i = 0; $i < $length; ++$i) {
            $map[$i] = $units;
            $byte = ord($code[$i]);

            if ($byte < 0x80 || $byte >= 0xC0) {
                // ASCII or a lead byte; 4-byte sequences are a surrogate pair in UTF-16
                $units += $byte >= 0xF0 ? 2 : 1;
            }
        }

        $map[$length] = $units;

        return static fn (int $offset): int => $map[$offset] ?? $units;
    }
}
