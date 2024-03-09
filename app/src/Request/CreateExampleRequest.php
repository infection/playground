<?php

declare(strict_types=1);

namespace App\Request;

use App\Entity\Example;
use App\Infection\Runner;
use App\Validator as AppAssert;
use Symfony\Component\Validator\Constraints as Assert;

class CreateExampleRequest
{
    /**
     * @Assert\NotBlank()
     * @AppAssert\ValidPhpCode()
     *
     * @var string
     */
    public $code;

    /**
     * @Assert\NotBlank()
     * @AppAssert\ValidPhpCode()
     *
     * @var string
     */
    public $test;

    /**
     * @Assert\NotBlank()
     * @AppAssert\JsonSchema(schemaFile="infection-config-schema.json")
     *
     * @var string
     */
    public $config;

    /**
     * @Assert\NotBlank()
     *
     * @var string|null
     */
    public $infectionVersion = Runner::CURRENT_INFECTION_VERSION;

    /**
     * @Assert\NotBlank()
     *
     * @var string|null
     */
    public $phpunitVersion = Runner::CURRENT_PHPUNIT_VERSION;

    /**
     * @Assert\NotBlank()
     *
     * @var string|null
     */
    public $phpVersion = Runner::CURRENT_PHP_VERSION;

    public static function fromEntity(Example $example): self
    {
        $self = new self();

        $self->code = $example->getCode();
        $self->test = $example->getTest();
        $self->config = $example->getConfig();
        $self->infectionVersion = $example->getInfectionVersion();
        $self->phpunitVersion = $example->getPhpunitVersion();
        $self->phpVersion = $example->getPhpVersion();

        return $self;
    }
}
