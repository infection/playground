<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class PlaygroundController
{
    /**
     * @Route(name="playground_index", path="/")
     */
    public function hello(): Response
    {
        return new Response('Hello - <> from volume!');
    }
}