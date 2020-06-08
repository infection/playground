<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Example;
use App\Form\CreateExampleType;
use App\Infection\Runner;
use App\Request\CreateExampleRequest;
use Hashids\Hashids;
use SensioLabs\AnsiConverter\AnsiToHtmlConverter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class PlaygroundController extends AbstractController
{
    /**
     * @var Hashids
     */
    private $hashids;

    /**
     * @var Runner
     */
    private $infectionRunner;

    public function __construct(Hashids $hashids, Runner $infectionRunner)
    {
        $this->hashids = $hashids;
        $this->infectionRunner = $infectionRunner;
    }

    /**
     * @Route(name="playground_index", path="/")
     */
    public function hello(): Response
    {
        $createExampleRequest = new CreateExampleRequest();

        $form = $this->createForm(CreateExampleType::class, $createExampleRequest);

        return $this->render('playground/index.html.twig', [
            'form' => $form->createView(),
        ]);
    }

    /**
     * @Route(name="playground_create_example", path="/r", methods={"POST"})
     */
    public function createExample(Request $request): Response
    {
        $createExampleRequest = new CreateExampleRequest();

        $form = $this->createForm(CreateExampleType::class, $createExampleRequest);

        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $example = new Example(
                $createExampleRequest->code,
                $createExampleRequest->test,
                $createExampleRequest->config
            );

            $em = $this->getDoctrine()->getManager();
            $em->persist($example);
            $em->flush();

            $idHash = $this->hashids->encode($example->getId());

            $ansiOutput = $this->infectionRunner->run(
                $idHash,
                $createExampleRequest->code,
                $createExampleRequest->test,
                $createExampleRequest->config
            );

            $example->updateResultOutput($ansiOutput);

            $em->flush();

            return $this->redirectToRoute('playground_display_example', ['exampleIdHash' => $idHash]);
        }

        return $this->render('playground/create.html.twig', [
            'form' => $form->createView(),
        ]);
    }

    /**
     * @Route(name="playground_display_example", path="/r/{exampleIdHash}", methods={"GET"})
     */
    public function displayExample(string $exampleIdHash): Response
    {
        /** @var Example|null $example */
        $example = $this->getDoctrine()->getManager()->find(Example::class, $this->hashids->decode($exampleIdHash)[0]);

        if (!$example instanceof Example) {
            throw $this->createNotFoundException();
        }

        $createExampleRequest = CreateExampleRequest::fromEntity($example);

        $form = $this->createForm(CreateExampleType::class, $createExampleRequest);

        $converter = new AnsiToHtmlConverter();

        return $this->render('playground/index.html.twig', [
            'form' => $form->createView(),
            'resultOutput' => $converter->convert($example->getResultOutput()),
            'example' => $createExampleRequest,
        ]);
    }
}
