<?php

declare(strict_types=1);

namespace App\Request;

use App\Entity\Example;

class CreateExampleRequest
{
    /**
     * @var string
     */
    public $code;

    /**
     * @var string
     */
    public $test;

    /**
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
