<?php

namespace Base\Office\Repository\Booking;

use Base\Office\Entity\Booking\ServiceArea;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<ServiceArea> */
class ServiceAreaRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ServiceArea::class);
    }

    /** @return list<ServiceArea> */
    public function findActive(): array
    {
        return $this->findBy(['active' => true], ['name' => 'ASC']);
    }

    /** The first active area holding that address, or null: outside every area. */
    public function findContaining(?string $postalCode, ?string $town = null, ?float $latitude = null, ?float $longitude = null): ?ServiceArea
    {
        foreach ($this->findActive() as $area) {
            if ($area->contains($postalCode, $town, $latitude, $longitude)) {
                return $area;
            }
        }

        return null;
    }

}
