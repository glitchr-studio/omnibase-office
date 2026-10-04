<?php

namespace Base\Office\Repository\Booking;

use Base\Office\Entity\Booking\AppointmentType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<AppointmentType> */
class AppointmentTypeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AppointmentType::class);
    }

    /** @return list<AppointmentType> the active types this member offers */
    public function findForMember(\Base\Office\Entity\Member $member): array
    {
        return $this->createQueryBuilder('t')
            ->innerJoin('t.members', 'm')->andWhere('m = :member')->setParameter('member', $member)
            ->andWhere('t.active = true')
            ->orderBy('t.position', 'ASC')->addOrderBy('t.name', 'ASC')
            ->getQuery()->getResult();
    }

}
