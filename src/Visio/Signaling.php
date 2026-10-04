<?php

namespace Base\Office\Visio;

use App\Entity\User;
use Base\Office\Entity\Visio\Room;
use Base\Office\Entity\Visio\Signal;
use Base\Office\Repository\Visio\SignalRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The WebRTC handshake relayed over plain HTTP: one side posts its offer,
 * its answer, its ICE candidates; the other asks every second, while the
 * call is being set up, for what came after the last one it read. No
 * WebSocket server to run; the media themselves never come here.
 */
class Signaling
{
    public const MAX_PAYLOAD = 65536;

    public function __construct(
        private readonly SignalRepository $signals,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function send(Room $room, User $sender, string $type, string $payload): Signal
    {
        if (!$room->isParticipant($sender)) {
            throw new \InvalidArgumentException('Not in this room.');
        }
        if (!\in_array($type, Signal::TYPES, true)) {
            throw new \InvalidArgumentException(sprintf('Unknown signal "%s".', $type));
        }
        if (\strlen($payload) > self::MAX_PAYLOAD || (null === json_decode($payload) && 'null' !== $payload)) {
            throw new \InvalidArgumentException('The signal is not a JSON document of reasonable size.');
        }

        $signal = new Signal($room, $sender, $type, $payload);
        $this->entityManager->persist($signal);
        if ('answer' === $type) {
            $room->markConnected();
        }
        $this->entityManager->flush();

        return $signal;
    }

    /**
     * What the other side sent after $after.
     *
     * @return list<array{id: int, type: string, payload: mixed}>
     */
    public function receive(Room $room, User $user, int $after = 0): array
    {
        return array_map(static fn (Signal $s) => ['id' => (int) $s->getId(), 'type' => $s->getType(), 'payload' => json_decode($s->getPayload(), true)], $this->signals->findForRecipient($room, $user, $after));
    }
}
