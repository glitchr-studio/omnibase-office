<?php

namespace Base\Office\Repository\Visio;

use Base\Office\Entity\Visio\Signal;
use Base\Office\Entity\Visio\Room;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Signal> */
class SignalRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Signal::class);
    }

    /** @return list<Signal> what the other side sent after that id */
    public function findForRecipient(Room $room, object $user, int $after = 0): array
    {
        return $this->createQueryBuilder('s')
            ->andWhere('s.room = :room')->setParameter('room', $room)
            ->andWhere('s.sender != :user')->setParameter('user', $user)
            ->andWhere('s.id > :after')->setParameter('after', $after)
            ->orderBy('s.id', 'ASC')->setMaxResults(200)
            ->getQuery()->getResult();
    }

    public function purgeRoom(Room $room): int
    {
        return $this->createQueryBuilder('s')->delete()->andWhere('s.room = :room')->setParameter('room', $room)->getQuery()->execute();
    }

    public function countForRoom(Room $room): int
    {
        return (int) $this->createQueryBuilder('s')->select('COUNT(s.id)')->andWhere('s.room = :room')->setParameter('room', $room)->getQuery()->getSingleScalarResult();
    }

}
