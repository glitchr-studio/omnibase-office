<?php

namespace Base\Office\Repository\Booking;

use Base\Office\Entity\Booking\Schedule;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Schedule> */
class ScheduleRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Schedule::class);
    }

    /** @return list<Schedule> */
    public function findForMember(\Base\Office\Entity\Member $member): array
    {
        return $this->findBy(['member' => $member], ['dayOfWeek' => 'ASC', 'startsAt' => 'ASC']);
    }

}
