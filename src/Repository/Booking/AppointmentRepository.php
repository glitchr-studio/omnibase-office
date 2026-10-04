<?php

namespace Base\Office\Repository\Booking;

use Base\Office\Entity\Booking\Appointment;
use Base\Office\Enum\AppointmentStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Appointment> */
class AppointmentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Appointment::class);
    }

    /** @return list<Appointment> the member's appointments holding a slot in [from, to) */
    public function findActiveBetween(\Base\Office\Entity\Member $member, \DateTimeInterface $from, \DateTimeInterface $to): array
    {
        return $this->createQueryBuilder('a')
            ->andWhere('a.member = :member')->setParameter('member', $member)
            ->andWhere('a.status IN (:active)')->setParameter('active', [AppointmentStatus::REQUESTED->value, AppointmentStatus::CONFIRMED->value])
            ->andWhere('a.startsAt IS NOT NULL')
            ->andWhere('a.startsAt < :to')->setParameter('to', \Base\Office\Database\Utc::of($to))
            ->andWhere('a.endsAt > :from')->setParameter('from', \Base\Office\Database\Utc::of($from))
            ->orderBy('a.startsAt', 'ASC')
            ->getQuery()->getResult();
    }

    /** @return list<Appointment> every appointment of the day(s), all members, for the agenda */
    public function findBetween(\DateTimeInterface $from, \DateTimeInterface $to, ?\Base\Office\Entity\Member $member = null, bool $withCancelled = false): array
    {
        $qb = $this->createQueryBuilder('a')
            ->addSelect('m', 't')->innerJoin('a.member', 'm')->innerJoin('a.type', 't')
            ->andWhere('a.startsAt >= :from')->setParameter('from', \Base\Office\Database\Utc::of($from))
            ->andWhere('a.startsAt < :to')->setParameter('to', \Base\Office\Database\Utc::of($to))
            ->orderBy('a.startsAt', 'ASC');
        if ($member) {
            $qb->andWhere('a.member = :member')->setParameter('member', $member);
        }
        if (!$withCancelled) {
            $qb->andWhere('a.status != :cancelled')->setParameter('cancelled', AppointmentStatus::CANCELLED->value);
        }

        return $qb->getQuery()->getResult();
    }

    /** @return list<Appointment> the client's appointments, upcoming first then the past */
    public function findForClient(object $client, bool $upcoming = true, int $limit = 50): array
    {
        $now = \Base\Office\Database\Utc::now();
        $qb = $this->createQueryBuilder('a')
            ->andWhere('a.client = :client')->setParameter('client', $client)
            ->setMaxResults($limit);
        if ($upcoming) {
            $qb->andWhere('(a.startsAt >= :now OR a.startsAt IS NULL)')->andWhere('a.status IN (:active)')
                ->setParameter('active', [AppointmentStatus::REQUESTED->value, AppointmentStatus::CONFIRMED->value])
                ->orderBy('a.startsAt', 'ASC');
        } else {
            $qb->andWhere('(a.startsAt < :now OR a.status NOT IN (:active))')
                ->setParameter('active', [AppointmentStatus::REQUESTED->value, AppointmentStatus::CONFIRMED->value])
                ->orderBy('a.startsAt', 'DESC');
        }
        $qb->setParameter('now', $now);

        return $qb->getQuery()->getResult();
    }

    /** Whether the client had an appointment with that member before (BookableBy::KNOWN). */
    public function hasSeen(object $client, \Base\Office\Entity\Member $member): bool
    {
        return (int) $this->createQueryBuilder('a')->select('COUNT(a.id)')
            ->andWhere('a.client = :client')->setParameter('client', $client)
            ->andWhere('a.member = :member')->setParameter('member', $member)
            ->andWhere('a.status IN (:kept)')->setParameter('kept', [AppointmentStatus::CONFIRMED->value, AppointmentStatus::DONE->value])
            ->getQuery()->getSingleScalarResult() > 0;
    }

    public function findOneByCancelToken(string $token): ?Appointment
    {
        return $this->findOneBy(['cancelTokenHash' => hash('sha256', $token)]);
    }

    /** @return list<Appointment> confirmed ones starting within [from, to), for the reminders */
    public function findConfirmedStartingBetween(\DateTimeInterface $from, \DateTimeInterface $to): array
    {
        return $this->createQueryBuilder('a')
            ->andWhere('a.status = :confirmed')->setParameter('confirmed', AppointmentStatus::CONFIRMED->value)
            ->andWhere('a.startsAt >= :from')->setParameter('from', \Base\Office\Database\Utc::of($from))
            ->andWhere('a.startsAt < :to')->setParameter('to', \Base\Office\Database\Utc::of($to))
            ->getQuery()->getResult();
    }

    /** @return list<Appointment> past ones still CONFIRMED: to close */
    public function findToClose(\DateTimeInterface $before): array
    {
        return $this->createQueryBuilder('a')
            ->andWhere('a.status = :confirmed')->setParameter('confirmed', AppointmentStatus::CONFIRMED->value)
            ->andWhere('a.endsAt < :before')->setParameter('before', \Base\Office\Database\Utc::of($before))
            ->getQuery()->getResult();
    }

    /** @return list<Appointment> requests waiting for the office */
    public function findRequested(int $limit = 20): array
    {
        return $this->createQueryBuilder('a')
            ->andWhere('a.status = :requested')->setParameter('requested', AppointmentStatus::REQUESTED->value)
            ->orderBy('a.createdAt', 'ASC')->setMaxResults($limit)
            ->getQuery()->getResult();
    }

    /** @return list<Appointment> */
    public function findSeries(string $series): array
    {
        return $this->findBy(['series' => $series], ['startsAt' => 'ASC']);
    }

    public function purgeEndedBefore(\DateTimeInterface $before): int
    {
        return $this->createQueryBuilder('a')->delete()
            ->andWhere('(a.endsAt < :before OR (a.endsAt IS NULL AND a.createdAt < :before))')
            ->setParameter('before', \Base\Office\Database\Utc::of($before))
            ->getQuery()->execute();
    }

}
