<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Example;
use App\Form\CreateExampleType;
use App\Request\CreateExampleRequest;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class PlaygroundController extends AbstractController
{
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

            // todo run infection to get the output
            $example->updateResultOutput('-- TODO --');

            $em = $this->getDoctrine()->getManager();
            $em->persist($example);
            $em->flush();

            return $this->redirectToRoute('playground_display_example', ['exampleHashId' => $example->getId()]);
        }

        return $this->render('playground/create.html.twig', [
            'form' => $form->createView(),
        ]);
    }

    /**
     * @Route(name="playground_display_example", path="/r/{exampleHashId}", methods={"GET"})
     */
    public function displayExample(string $exampleHashId): Response
    {
        /** @var Example|null $example */
        $example = $this->getDoctrine()->getManager()->find(Example::class, $exampleHashId);

        if (!$example instanceof Example) {
            throw $this->createNotFoundException();
        }

        $createExampleRequest = CreateExampleRequest::fromEntity($example);

        $form = $this->createForm(CreateExampleType::class, $createExampleRequest);

        return $this->render('playground/index.html.twig', [
            'form' => $form->createView(),
            'resultOutput' => $example->getResultOutput(),
        ]);
    }
}
