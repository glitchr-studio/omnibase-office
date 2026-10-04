<?php

namespace Base\Office\Repository;

use Base\Office\Entity\WalkIn;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<WalkIn> */
class WalkInRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WalkIn::class);
    }

    /** @return list<WalkIn> */
    public function findActive(): array
    {
        return $this->findBy(['active' => true], ['opensAt' => 'ASC', 'id' => 'ASC']);
    }

}
