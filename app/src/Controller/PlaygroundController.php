<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class PlaygroundController extends AbstractController
{
    /**
     * @Route(name="playground_index", path="/")
     */
    public function hello(): Response
    {
        return $this->render('playground/hello.html.twig', [
            'name' => 'Infection',
        ]);
    }
}
