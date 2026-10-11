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

use App\Entity\AstRun;
use App\PhpParser\XRay\XRayBuilder;
use App\Repository\AstRunRepository;
use Doctrine\ORM\EntityManagerInterface;
use Hashids\Hashids;
use function is_array;
use function is_string;
use function json_decode;
use function mb_strlen;
use PhpParser\Error;
use function sprintf;
use function str_starts_with;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use function trim;

/**
 * AST explorer with AST X-Ray. Code is analyzed live via /ast/xray and saved only when shared.
 *
 * Shared links are /ast/{astRunIdHash}/{activeNodeId}, where activeNodeId is the 1-based
 * pre-order index of the node in the AST (as in the original AST explorer, so old links keep working).
 */
class AstController extends AbstractController
{
    /**
     * Longer code produces an AST too big to build and render in the browser.
     */
    public const MAX_CODE_LENGTH = 20_000;

    public function __construct(
        private Hashids $hashids,
        private AstRunRepository $astRunRepository,
        private XRayBuilder $xRayBuilder,
    ) {
    }

    #[Route('/ast', name: 'app_ast_index', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('ast/index.html.twig', [
            'code' => null,
            'idHash' => null,
            'activeNodeId' => null,
            'xray' => null,
        ]);
    }

    #[Route('/ast/xray', name: 'app_ast_xray', methods: ['POST'])]
    public function xray(Request $request): JsonResponse
    {
        $code = $this->extractCode($request);

        if ($code instanceof JsonResponse) {
            return $code;
        }

        try {
            return new JsonResponse($this->xRayBuilder->build($code));
        } catch (Error $e) {
            return $this->parseErrorResponse($e, $code);
        }
    }

    #[Route('/ast/share', name: 'app_ast_share', methods: ['POST'])]
    public function share(Request $request, EntityManagerInterface $em): JsonResponse
    {
        $code = $this->extractCode($request);

        if ($code instanceof JsonResponse) {
            return $code;
        }

        if (trim($code) === '') {
            return new JsonResponse(['error' => ['message' => 'Code must not be blank']], Response::HTTP_BAD_REQUEST);
        }

        // stored code is always loaded with an opening tag, so node ids in the link match what the user sees
        $code = self::ensureOpeningTag($code);

        try {
            $this->xRayBuilder->build($code);
        } catch (Error $e) {
            return $this->parseErrorResponse($e, $code);
        }

        $astRun = $this->astRunRepository->findContentByHash(AstRun::hashInput($code));

        if (!$astRun instanceof AstRun) {
            $astRun = new AstRun($code);

            $em->persist($astRun);
            $em->flush();

            $astRun->setIdHash($this->hashids->encode($astRun->getId()));
            $em->flush();
        }

        $idHash = (string) $astRun->getIdHash();

        return new JsonResponse([
            'idHash' => $idHash,
            'code' => $code,
            'url' => $this->generateUrl('app_ast_display', ['astRunIdHash' => $idHash]),
        ]);
    }

    #[Route('/ast/{astRunIdHash}/{activeNodeId}', name: 'app_ast_display', requirements: ['activeNodeId' => '\d+'], defaults: ['activeNodeId' => null], methods: ['GET'])]
    public function display(EntityManagerInterface $em, string $astRunIdHash, ?int $activeNodeId = null): Response
    {
        $ids = $this->hashids->decode($astRunIdHash);
        /** @var AstRun|null $astRun */
        $astRun = $ids === [] ? null : $em->find(AstRun::class, $ids[0]);

        if (!$astRun instanceof AstRun) {
            throw $this->createNotFoundException();
        }

        // the original AST explorer parsed code without an opening tag as if it had one
        $code = self::ensureOpeningTag($astRun->getCode());

        try {
            $xray = $this->xRayBuilder->build($code);
        } catch (Error) {
            $xray = null;
        }

        return $this->render('ast/index.html.twig', [
            'code' => $code,
            'idHash' => $astRun->getIdHash() ?? $astRunIdHash,
            'activeNodeId' => $activeNodeId,
            'xray' => $xray,
        ]);
    }

    private function extractCode(Request $request): string|JsonResponse
    {
        $payload = json_decode($request->getContent(), true);
        $code = is_array($payload) ? ($payload['code'] ?? null) : null;

        if (!is_string($code)) {
            return new JsonResponse(['error' => ['message' => 'Expected JSON body {"code": "<?php ..."}']], Response::HTTP_BAD_REQUEST);
        }

        if (mb_strlen($code) > self::MAX_CODE_LENGTH) {
            return new JsonResponse(['error' => ['message' => sprintf('Code is too long. It should have %d characters or less.', self::MAX_CODE_LENGTH)]], Response::HTTP_REQUEST_ENTITY_TOO_LARGE);
        }

        return $code;
    }

    private function parseErrorResponse(Error $e, string $code): JsonResponse
    {
        return new JsonResponse([
            'error' => [
                'message' => $e->getRawMessage(),
                'startLine' => $e->getStartLine(),
                'startColumn' => $e->hasColumnInfo() ? $e->getStartColumn($code) : null,
                'endColumn' => $e->hasColumnInfo() ? $e->getEndColumn($code) : null,
            ],
        ], Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    private static function ensureOpeningTag(string $code): string
    {
        return str_starts_with(trim($code), '<?php') ? $code : '<?php ' . $code;
    }
}
