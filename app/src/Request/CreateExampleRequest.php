<?php

declare(strict_types=1);

namespace App\Request;

use App\Entity\Example;
use App\Infection\Runner;
use App\Validator as AppAssert;
use Symfony\Component\Validator\Constraints as Assert;

class CreateExampleRequest
{
    #[AppAssert\ValidPhpCode]
    #[Assert\NotBlank]
    public $code;

    #[AppAssert\ValidPhpCode]
    #[Assert\NotBlank]
    public string $test;

    #[AppAssert\JsonSchema(['schemaFile' => "infection-config-schema.json"])]
    #[Assert\NotBlank]
    public string $config;

    #[Assert\NotBlank]
    public string|null $infectionVersion = Runner::CURRENT_INFECTION_VERSION;

    #[Assert\NotBlank]
    public string|null $phpunitVersion = Runner::CURRENT_PHPUNIT_VERSION;

    #[Assert\NotBlank]
    public string|null $phpVersion = Runner::CURRENT_PHP_VERSION;

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
