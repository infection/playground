<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Example;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;

final class ExampleRepository
{
    /**
     * @var EntityRepository<Example>
     */
    private EntityRepository $repository;

    public function __construct(EntityManagerInterface $entityManager)
    {
        $this->repository = $entityManager->getRepository(Example::class);
    }

    public function findContentByHash(string $inputHash): ?Example
    {
        return $this->repository->findOneBy(['inputHash' => $inputHash]);
    }
}
