<?php

declare(strict_types=1);

namespace App\Request;

use App\Entity\Example;
use Symfony\Component\Validator\Constraints as Assert;

class CreateExampleRequest
{
    /**
     * @Assert\NotBlank()
     *
     * @var string
     */
    public $code;

    /**
     * @Assert\NotBlank()
     *
     * @var string
     */
    public $test;

    /**
     * @Assert\NotBlank()
     *
     * @var string
     */
    public $config;

    /**
     * @var string
     */
    public $resultOutput;

    public static function fromEntity(Example $example): self
    {
        $self = new self();

        $self->code = $example->getCode();
        $self->test = $example->getTest();
        $self->config = $example->getConfig();

        return $self;
    }
}
