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

use App\PhpParser\XRay\XRayBuilder;
use function is_array;
use function is_string;
use function json_decode;
use PhpParser\Error;
use function strlen;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class AstXRayController extends AbstractController
{
    private const MAX_CODE_LENGTH = 100_000;

    public function __construct(
        private XRayBuilder $xRayBuilder,
    ) {
    }

    #[Route('/ast2', name: 'app_ast2_index', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('ast2/index.html.twig');
    }

    #[Route('/ast2/xray', name: 'app_ast2_xray', methods: ['POST'])]
    public function xray(Request $request): JsonResponse
    {
        $payload = json_decode($request->getContent(), true);
        $code = is_array($payload) ? ($payload['code'] ?? null) : null;

        if (!is_string($code)) {
            return new JsonResponse(['error' => ['message' => 'Expected JSON body {"code": "<?php ..."}']], Response::HTTP_BAD_REQUEST);
        }

        if (strlen($code) > self::MAX_CODE_LENGTH) {
            return new JsonResponse(['error' => ['message' => 'Code is too long']], Response::HTTP_REQUEST_ENTITY_TOO_LARGE);
        }

        try {
            return new JsonResponse($this->xRayBuilder->build($code));
        } catch (Error $e) {
            return new JsonResponse([
                'error' => [
                    'message' => $e->getRawMessage(),
                    'startLine' => $e->getStartLine(),
                    'startColumn' => $e->hasColumnInfo() ? $e->getStartColumn($code) : null,
                    'endColumn' => $e->hasColumnInfo() ? $e->getEndColumn($code) : null,
                ],
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
    }
}
