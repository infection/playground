<?php

declare(strict_types=1);

namespace App\Controller;

use App\Code\CodeSanitizer;
use App\Entity\Example;
use App\Form\CreateExampleType;
use App\Html\Ansi\AnsiToHtmlConverter;
use App\Html\Ansi\InfectionAnsiHtmlTheme;
use App\Infection\ConfigBuilder;
use App\Infection\Runner;
use App\Repository\ExampleRepository;
use App\Request\CreateExampleRequest;
use Doctrine\ORM\EntityManagerInterface;
use Hashids\Hashids;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
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
        ExampleRepository $exampleRepository
    ) {
        $this->hashids = $hashids;
        $this->infectionRunner = $infectionRunner;
        $this->codeSanitizer = $codeSanitizer;
        $this->configBuilder = $configBuilder;
        $this->exampleRepository = $exampleRepository;
    }

    #[Route("/", name: "playground_index")]
    public function index(): Response
    {
        $createExampleRequest = new CreateExampleRequest();

        $form = $this->createForm(CreateExampleType::class, $createExampleRequest);

        return $this->render('playground/index.html.twig', [
            'form' => $form->createView(),
        ]);
    }

    #[Route("/r", name: "playground_create_example", methods: ["POST"])]
    public function createExample(Request $request, EntityManagerInterface $em): Response
    {
        $createExampleRequest = new CreateExampleRequest();

        $form = $this->createForm(CreateExampleType::class, $createExampleRequest);

        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $code = $this->codeSanitizer->sanitize($createExampleRequest->code);
            $test = $this->codeSanitizer->sanitize($createExampleRequest->test);

            $originalConfig = $createExampleRequest->config;

            $existingExample = $this->exampleRepository->findContentByHash(
                Example::hashInput($code, $test, $originalConfig, Runner::CURRENT_INFECTION_VERSION, Runner::CURRENT_PHPUNIT_VERSION, Runner::CURRENT_PHP_VERSION)
            );

            if ($existingExample instanceof Example) {
                return $this->redirectToRoute('playground_display_example', ['exampleIdHash' => $this->hashids->encode($existingExample->getId())]);
            }

            $example = new Example($code, $test, $originalConfig, Runner::CURRENT_INFECTION_VERSION, Runner::CURRENT_PHPUNIT_VERSION, Runner::CURRENT_PHP_VERSION);

            $em->persist($example);
            $em->flush();

            $idHash = $this->hashids->encode($example->getId());

            $runResult = $this->infectionRunner->run(
                $idHash,
                $code,
                $test,
                $this->configBuilder->build($originalConfig)
            );

            $example->setIdHash($idHash);
            $example->updateResultOutput($runResult->getAnsiOutput());
            $example->updateJsonLog($runResult->getJsonLog());

            $em->flush();

            return $this->redirectToRoute('playground_display_example', ['exampleIdHash' => $idHash]);
        }

        return $this->render('playground/create.html.twig', [
            'form' => $form->createView(),
            'example' => $createExampleRequest,
        ]);
    }

    #[Route("/r/{exampleIdHash}", name: "playground_display_example", methods: ["GET"])]
    public function displayExample(string $exampleIdHash, EntityManagerInterface $em): Response
    {
        /** @var Example|null $example */
        $example = $em->find(Example::class, $this->hashids->decode($exampleIdHash)[0]);

        if (!$example instanceof Example) {
            throw $this->createNotFoundException();
        }

        $createExampleRequest = CreateExampleRequest::fromEntity($example);

        $form = $this->createForm(CreateExampleType::class, $createExampleRequest);

        $converter = new AnsiToHtmlConverter(new InfectionAnsiHtmlTheme());
        $converter->setInvertBackground(true);

        return $this->render('playground/index.html.twig', [
            'form' => $form->createView(),
            'resultOutput' => $converter->convert($example->getResultOutput()),
            'example' => $createExampleRequest,
            'jsonLog' => $example->getJsonLog(),
        ]);
    }
}
