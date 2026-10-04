<?php

namespace Base\Office\Repository;

use Base\Office\Entity\Office;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Office> */
class OfficeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Office::class);
    }

    public function findMain(): ?Office
    {
        return $this->findOneBy(['main' => true], ['position' => 'ASC']) ?? $this->findOneBy([], ['position' => 'ASC']);
    }

    /** @return list<Office> */
    public function findOrdered(): array
    {
        return $this->findBy([], ['main' => 'DESC', 'position' => 'ASC', 'name' => 'ASC']);
    }

}
