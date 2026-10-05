<?php

namespace Base\Office\Repository\Visio;

use App\Entity\User;
use Base\Office\Entity\Visio\Room;
use Base\Office\Entity\Visio\Signal;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Omnimeet\Direct\Signal as Message;
use Omnimeet\Direct\SignalStoreInterface;

/**
 * The handshake's storage: omnimeet/direct's rules (Omnimeet\Direct\Signaling)
 * keep their messages here, as Signal rows - a room named by its reference
 * on the gateway, a participant by "u<id>" (Base\Office\Visio\Rooms::participantId()).
 *
 * @extends ServiceEntityRepository<Signal>
 */
class SignalRepository extends ServiceEntityRepository implements SignalStoreInterface
{
    /** @var array<string, int> the rooms' ids by reference: they never change */
    private array $rooms = [];

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Signal::class);
    }

    public function append(string $room, string $sender, string $type, string $payload): int
    {
        $entityManager = $this->getEntityManager();
        $signal = new Signal(
            $entityManager->getReference(Room::class, $this->roomId($room) ?? throw new \InvalidArgumentException(\sprintf('No room "%s".', $room))),
            $entityManager->getReference(User::class, self::userId($sender) ?? throw new \InvalidArgumentException(\sprintf('"%s" is not a participant.', $sender))),
            $type,
            $payload,
        );
        $entityManager->persist($signal);
        $entityManager->flush();

        return (int) $signal->getId();
    }

    public function read(string $room, int $after = 0, ?string $except = null, int $limit = 200): array
    {
        $id = $this->roomId($room);
        if (null === $id) {
            return [];
        }
        $query = $this->createQueryBuilder('s')
            ->select('s.id AS id', 'IDENTITY(s.sender) AS sender', 's.type AS type', 's.payload AS payload')
            ->andWhere('IDENTITY(s.room) = :room')->setParameter('room', $id)
            ->andWhere('s.id > :after')->setParameter('after', $after)
            ->orderBy('s.id', 'ASC')->setMaxResults($limit);
        if (null !== $except) {
            $query->andWhere('IDENTITY(s.sender) != :except')->setParameter('except', self::userId($except) ?? 0);
        }

        return array_map(static fn (array $row) => new Message((int) $row['id'], $room, 'u'.$row['sender'], (string) $row['type'], (string) $row['payload']), $query->getQuery()->getArrayResult());
    }

    public function purge(string $room): int
    {
        $id = $this->roomId($room);

        return null === $id ? 0 : (int) $this->createQueryBuilder('s')->delete()->andWhere('IDENTITY(s.room) = :room')->setParameter('room', $id)->getQuery()->execute();
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

    private function roomId(string $reference): ?int
    {
        if (!isset($this->rooms[$reference])) {
            $id = $this->getEntityManager()->createQueryBuilder()->select('r.id')->from(Room::class, 'r')
                ->andWhere('r.reference = :reference')->setParameter('reference', $reference)
                ->setMaxResults(1)->getQuery()->getOneOrNullResult()['id'] ?? null;
            if (null === $id) {
                return null;
            }
            $this->rooms[$reference] = (int) $id;
        }

        return $this->rooms[$reference];
    }

    private static function userId(string $participant): ?int
    {
        return preg_match('/^u(\d+)$/', $participant, $m) ? (int) $m[1] : null;
    }
}
