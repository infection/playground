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

namespace App\Controller;

use App\Code\CodeSanitizer;
use App\Entity\AstRun;
use App\Entity\Example;
use App\Form\CreateAstRunType;
use App\Form\CreateExampleType;
use App\Html\Ansi\AnsiToHtmlConverter;
use App\Html\Ansi\InfectionAnsiHtmlTheme;
use App\Infection\ConfigBuilder;
use App\Infection\Runner;
use App\PhpParser\ClickablePrinter;
use App\PhpParser\NodeResolver\FocusedNodeResolver;
use App\PhpParser\SimpleNodeDumper;
use App\PhpParser\SimplePhpParser;
use App\Repository\AstRunRepository;
use App\Repository\ExampleRepository;
use App\Request\CreateAstRunRequest;
use App\Request\CreateExampleRequest;
use Doctrine\ORM\EntityManagerInterface;
use Hashids\Hashids;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use PhpParser\Node;
use PhpParser\Node\Attribute;
use PhpParser\Node\AttributeGroup;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Param;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\UseUse;
use function is_int;

class AstController extends AbstractController
{
    public function __construct(
        private Hashids $hashids,
        private AstRunRepository $astRunRepository,
        private SimplePhpParser $simplePhpParser,
        private FocusedNodeResolver $focusedNodeResolver
    ) {
    }

    #[Route('/ast', name: 'app_ast_index', methods: ['GET'])]
    public function index(): Response
    {
        $createExampleRequest = new CreateAstRunRequest();

        $form = $this->createForm(CreateAstRunType::class, $createExampleRequest);

        return $this->render('ast/index.html.twig', [
            'form' => $form->createView(),
        ]);
    }

    #[Route('/r', name: 'app_ast_create', methods: ['POST'])]
    public function createExample(Request $request, EntityManagerInterface $em): Response
    {
        $createAstRunRequest = new CreateAstRunRequest();

        $form = $this->createForm(CreateAstRunType::class, $createAstRunRequest);

        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $existingAstRun = $this->astRunRepository->findContentByHash(
                AstRun::hashInput($createAstRunRequest->code)
            );

            if ($existingAstRun instanceof AstRun) {
                return $this->redirectToRoute('app_ast_display', ['astRunIdHash' => $this->hashids->encode($existingAstRun->getId())]);
            }

            $astRun = new AstRun($createAstRunRequest->code);

            $em->persist($astRun);
            $em->flush();

            $idHash = $this->hashids->encode($astRun->getId());

            $astRun->setIdHash($idHash);
            $em->flush();

            return $this->redirectToRoute('app_ast_display', ['astRunIdHash' => $idHash]);
        }

        return $this->render('ast/create.html.twig', [
            'form' => $form->createView(),
            'example' => $createAstRunRequest,
        ]);
    }

    #[Route('/ast/{astRunIdHash}/{activeNodeId}', name: 'app_ast_display', defaults: ['activeNodeId' => null], methods: ['GET'])]
    public function displayExample(EntityManagerInterface $em, string $astRunIdHash, int|null $activeNodeId = null): Response
    {
        /** @var AstRun|null $astRun */
        $astRun = $em->find(AstRun::class, $this->hashids->decode($astRunIdHash)[0]);

        if (!$astRun instanceof AstRun) {
            throw $this->createNotFoundException();
        }

        $createAstRequest = CreateAstRunRequest::fromEntity($astRun);

        $nodes = $this->simplePhpParser->parseString($astRun->getCode());

        $focusedNode = is_int($activeNodeId) && $activeNodeId > 0
            ? $this->focusedNodeResolver->focus($nodes, $activeNodeId)
            : null;

        if ($focusedNode instanceof Node) {
            $simpleNodeDump = SimpleNodeDumper::dump($focusedNode);
            $targetNodeClass = $this->resolveTargetNodeClass($focusedNode);
        } else {
            $simpleNodeDump = SimpleNodeDumper::dump($nodes);
            $targetNodeClass = null;
        }

        $form = $this->createForm(CreateAstRunType::class, $createAstRequest);

        return $this->render('ast/index.html.twig', [
            'form' => $form->createView(),
            'astRun' => $createAstRequest,
            'clickableNodesDump' => $this->makeNodeClickable($nodes, $astRunIdHash, $activeNodeId),
            'simpleNodeDump' => $simpleNodeDump,
        ]);
    }

    /**
     * @param Node[] $nodes
     */
    private function makeNodeClickable(array $nodes, string $uuid, ?int $activeNodeId): string
    {
        $clickablePrinter = new ClickablePrinter($uuid, $activeNodeId);

        return $clickablePrinter->prettyPrint($nodes);
    }

    private function resolveTargetNodeClass(Node $node): string
    {
        if ($node instanceof UseUse || $node instanceof AttributeGroup) {
            $parentNode = $node->getAttribute('parent');
            return $parentNode::class;
        }

        if ($node instanceof Attribute) {
            $attributeGroup = $node->getAttribute('parent');
            $stmt = $attributeGroup->getAttribute('parent');
            return $stmt::class;
        }

        if ($node instanceof Stmt) {
            return $node::class;
        }

        if ($node instanceof Variable) {
            $parentNode = $node->getAttribute('parent');

            // special case
            if ($parentNode instanceof Param) {
                return $parentNode::class;
            }
        }

        // target one level up
        if ($node instanceof Identifier || $node instanceof Name || $node instanceof Variable) {
            $parentNode = $node->getAttribute('parent');
            return $this->resolveTargetNodeClass($parentNode);
        }

        return $node::class;
    }
}
