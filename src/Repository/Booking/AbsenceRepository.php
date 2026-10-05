<?php

namespace Base\Office\Repository\Booking;

use Base\Office\Entity\Booking\Absence;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Absence> */
class AbsenceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Absence::class);
    }

    /** @return list<Absence> the member's absences overlapping [from, to) */
    public function findOverlapping(\Base\Office\Entity\Member $member, \DateTimeInterface $from, \DateTimeInterface $to): array
    {
        return $this->createQueryBuilder('a')
            ->andWhere('a.member = :member')->setParameter('member', $member)
            ->andWhere('a.startsAt < :to')->setParameter('to', \Base\Database\Type\Utc::from($to))
            ->andWhere('a.endsAt > :from')->setParameter('from', \Base\Database\Type\Utc::from($from))
            ->getQuery()->getResult();
    }

}
