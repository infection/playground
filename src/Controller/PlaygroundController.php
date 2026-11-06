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

use App\Code\ClassesExtractor\LineMapBuilder;
use App\Code\CodeSanitizer;
use App\Entity\Example;
use App\Explore\ExampleStatsUpdater;
use App\Form\CreateExampleType;
use App\Html\Ansi\AnsiToHtmlConverter;
use App\Html\Ansi\InfectionAnsiHtmlTheme;
use App\Infection\ConfigBuilder;
use App\Infection\Runner;
use App\Infection\StaticAnalysisTool;
use App\Repository\ExampleRepository;
use App\Request\CreateExampleRequest;
use function array_column;
use Doctrine\ORM\EntityManagerInterface;
use Hashids\Hashids;
use function json_encode;
use const JSON_THROW_ON_ERROR;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormErrorIterator;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class PlaygroundController extends AbstractController
{
    private Hashids $hashids;

    private Runner $infectionRunner;

    private CodeSanitizer $codeSanitizer;

    private ConfigBuilder $configBuilder;

    private ExampleRepository $exampleRepository;

    public function __construct(
        Hashids $hashids,
        Runner $infectionRunner,
        CodeSanitizer $codeSanitizer,
        ConfigBuilder $configBuilder,
        ExampleRepository $exampleRepository,
        private readonly LineMapBuilder $lineMapBuilder,
        private readonly ExampleStatsUpdater $exampleStatsUpdater,
    ) {
        $this->hashids = $hashids;
        $this->infectionRunner = $infectionRunner;
        $this->codeSanitizer = $codeSanitizer;
        $this->configBuilder = $configBuilder;
        $this->exampleRepository = $exampleRepository;
    }

    #[Route('/', name: 'playground_index')]
    public function index(): Response
    {
        $createExampleRequest = new CreateExampleRequest();

        $form = $this->createForm(CreateExampleType::class, $createExampleRequest);

        return $this->renderPlayground($form);
    }

    #[Route('/r', name: 'playground_create_example', methods: ['POST'])]
    public function createExample(Request $request, EntityManagerInterface $em): Response
    {
        $createExampleRequest = new CreateExampleRequest();

        $form = $this->createForm(CreateExampleType::class, $createExampleRequest);

        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $code = $this->codeSanitizer->sanitize((string) $createExampleRequest->code);
            $test = $this->codeSanitizer->sanitize($createExampleRequest->test);

            $originalConfig = $createExampleRequest->config;
            $staticAnalysisTool = $createExampleRequest->staticAnalysisTool;
            $staticAnalysisToolVersion = $staticAnalysisTool?->getCurrentVersion();
            $infectionCommit = $createExampleRequest->infectionCommit;
            $infectionVersion = $infectionCommit->sha ?? Runner::CURRENT_INFECTION_VERSION;

            $existingExample = $this->exampleRepository->findContentByHash(
                Example::hashInput($code, $test, $originalConfig, $infectionVersion, Runner::CURRENT_PHPUNIT_VERSION, Runner::CURRENT_PHP_VERSION, $staticAnalysisTool, $staticAnalysisToolVersion)
            );

            if ($existingExample instanceof Example && $existingExample->hasFinishedRun()) {
                return $this->redirectToRoute('playground_display_example', ['exampleIdHash' => $this->hashids->encode($existingExample->getId())]);
            }

            // the same input, but its run never finished: it runs again, or the input could never be run
            $example = $existingExample;

            if (!$example instanceof Example) {
                $example = new Example($code, $test, $originalConfig, $infectionVersion, Runner::CURRENT_PHPUNIT_VERSION, Runner::CURRENT_PHP_VERSION, $staticAnalysisTool, $staticAnalysisToolVersion, $infectionCommit?->pullRequest);

                $em->persist($example);
                $em->flush();
            }

            $idHash = $this->hashids->encode($example->getId());

            $runResult = $this->infectionRunner->run(
                $idHash,
                $code,
                $test,
                $this->configBuilder->build($originalConfig, $staticAnalysisTool),
                $staticAnalysisTool,
                $infectionCommit,
            );

            // a run whose build failed is not finished: the same input runs again, and builds again
            if (!$runResult->isBuildFailure()) {
                $example->setIdHash($idHash);
            }

            $example->updateResultOutput($runResult->getAnsiOutput());
            $example->updateJsonLog($runResult->getJsonLog());
            $this->exampleStatsUpdater->update($example);

            $em->flush();

            return $this->redirectToRoute('playground_display_example', ['exampleIdHash' => $idHash]);
        }

        return $this->renderPlayground($form, $createExampleRequest);
    }

    #[Route('/r/{exampleIdHash}', name: 'playground_display_example', methods: ['GET'])]
    public function displayExample(string $exampleIdHash, EntityManagerInterface $em): Response
    {
        /** @var Example|null $example */
        $example = $em->find(Example::class, $this->hashids->decode($exampleIdHash)[0]);

        if (!$example instanceof Example) {
            throw $this->createNotFoundException();
        }

        $createExampleRequest = CreateExampleRequest::fromEntity($example);

        $form = $this->createForm(CreateExampleType::class, $createExampleRequest);

        $converter = new AnsiToHtmlConverter(new InfectionAnsiHtmlTheme(), false);
        $converter->setInvertBackground(true);

        return $this->renderPlayground(
            $form,
            $createExampleRequest,
            $converter->convert($example->getResultOutput()),
            $example->getJsonLog(),
            json_encode($this->lineMapBuilder->build($example->getCode(), $example->getJsonLog()), JSON_THROW_ON_ERROR),
        );
    }

    private function renderPlayground(
        FormInterface $form,
        ?CreateExampleRequest $example = null,
        ?string $resultOutput = null,
        ?string $jsonLog = null,
        ?string $lineMap = null,
    ): Response {
        return $this->render('playground/index.html.twig', [
            'form' => $form->createView(),
            'fieldErrors' => $this->describeErrors($form),
            'staticAnalysisTools' => array_column(StaticAnalysisTool::cases(), null, 'value'),
            // the versions of this run, or of a new one: a run of Infection built from a commit shows the pull request and the commit
            'infectionVersion' => $example?->infectionCommit?->getLabel() ?? $example->infectionVersion ?? Runner::CURRENT_INFECTION_VERSION,
            'phpunitVersion' => $example->phpunitVersion ?? Runner::CURRENT_PHPUNIT_VERSION,
            'phpVersion' => $example->phpVersion ?? Runner::CURRENT_PHP_VERSION,
            // NULL when the run used no static analysis, or for a new run: the toolchain shows the current versions
            'staticAnalysisToolVersion' => $example?->staticAnalysisToolVersion,
            'example' => $example,
            'resultOutput' => $resultOutput,
            'jsonLog' => $jsonLog,
            'lineMap' => $lineMap,
        ]);
    }

    /**
     * @return list<array{field: string|null, message: string}> field is NULL for an error of the whole form
     */
    private function describeErrors(FormInterface $form): array
    {
        $errors = [];

        /** @var FormErrorIterator<FormError> $formErrors */
        $formErrors = $form->getErrors(true);

        foreach ($formErrors as $error) {
            // a form sets itself as the origin of an error added to it
            /** @var FormInterface $origin */
            $origin = $error->getOrigin();

            $errors[] = [
                'field' => $origin === $form ? null : $origin->getName(),
                'message' => $error->getMessage(),
            ];
        }

        return $errors;
    }
}
