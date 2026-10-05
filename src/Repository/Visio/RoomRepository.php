<?php

namespace Base\Office\Repository\Visio;

use Base\Office\Entity\Visio\Room;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Room> */
class RoomRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Room::class);
    }

    public function findOneByToken(string $token): ?Room
    {
        return $this->findOneBy(['token' => $token]);
    }

    public function findOneBySubject(string $key): ?Room
    {
        return $this->findOneBy(['subjectKey' => $key]);
    }

    /** The room a gateway knows by that name (glitchr/omnimeet's reference), on that gateway when it is given. */
    public function findOneByReference(string $reference, ?string $gateway = null): ?Room
    {
        return $this->findOneBy(['reference' => $reference] + (null !== $gateway ? ['gateway' => $gateway] : []));
    }

    /** @return list<Room> rooms open now whose guest waits for the host */
    public function findWaiting(int $seconds = 20): array
    {
        $now = \Base\Database\Type\Utc::now();

        return array_values(array_filter(
            $this->createQueryBuilder('r')
                ->andWhere('r.endedAt IS NULL')
                ->andWhere('r.opensAt <= :now')->andWhere('r.closesAt >= :now')->setParameter('now', $now)
                ->andWhere('r.guestSeenAt >= :since')->setParameter('since', $now->modify(sprintf('-%d seconds', $seconds)))
                ->getQuery()->getResult(),
            static fn (Room $room) => $room->isGuestWaiting($seconds, $now),
        ));
    }

    /** @return list<Room> closed rooms whose handshake can go */
    public function findClosedBefore(\DateTimeInterface $before): array
    {
        return $this->createQueryBuilder('r')
            ->andWhere('r.closesAt < :before OR r.endedAt IS NOT NULL')->setParameter('before', \Base\Database\Type\Utc::from($before))
            ->getQuery()->getResult();
    }

}
