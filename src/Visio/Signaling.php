<?php

namespace Base\Office\Visio;

use App\Entity\User;
use Base\Office\Entity\Visio\Room;
use Base\Office\Entity\Visio\Signal;
use Base\Office\Repository\Visio\SignalRepository;
use Doctrine\ORM\EntityManagerInterface;
use Omnimeet\Direct\Signaling as DirectSignaling;

/**
 * The handshake under its former name and signatures (a Room, a User): the
 * rules are omnimeet/direct's (Omnimeet\Direct\Signaling), kept here in the
 * Signal rows.
 *
 * @deprecated since omnibase/office 1.1: use Omnimeet\Direct\Signaling with Rooms::participantId() and the room's reference; removed in 2.0
 */
class Signaling
{
    public const MAX_PAYLOAD = DirectSignaling::MAX_PAYLOAD;

    public function __construct(
        private readonly DirectSignaling $signaling,
        private readonly SignalRepository $signals,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function send(Room $room, User $sender, string $type, string $payload): Signal
    {
        if (!$room->isParticipant($sender)) {
            throw new \InvalidArgumentException('Not in this room.');
        }
        $signal = $this->signaling->send((string) $room->getReference(), Rooms::participantId($sender), $type, $payload);
        if ($signal->connects()) {
            $room->markConnected();
            $this->entityManager->flush();
        }

        return $this->signals->find($signal->id);
    }

    /**
     * What the other side sent after $after.
     *
     * @return list<array{id: int, type: string, payload: mixed}>
     */
    public function receive(Room $room, User $user, int $after = 0): array
    {
        return $this->signaling->receive((string) $room->getReference(), Rooms::participantId($user), $after);
    }
}
