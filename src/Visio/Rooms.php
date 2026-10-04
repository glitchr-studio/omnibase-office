<?php

namespace Base\Office\Visio;

use App\Entity\User;
use Base\Office\Entity\Visio\Room;
use Base\Office\Repository\Visio\RoomRepository;
use Base\Office\Repository\Visio\SignalRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The room of a subject: made the first time it is asked for, its times
 * kept in step with the subject's (an appointment moved), opened
 * office.visio.open_before minutes before and closed close_after minutes
 * after the end. Closing purges the handshake.
 */
class Rooms
{
    public function __construct(
        private readonly RoomRepository $rooms,
        private readonly SignalRepository $signals,
        private readonly EntityManagerInterface $entityManager,
        #[Autowire('%office.visio.open_before%')] private readonly int $openBefore = 10,
        #[Autowire('%office.visio.close_after%')] private readonly int $closeAfter = 30,
    ) {
    }

    public function forSubject(RoomSubjectInterface $subject): Room
    {
        $start = $subject->getVisioStartsAt() ?? throw new \LogicException('A video room needs a time.');
        $end = $subject->getVisioEndsAt() ?? $start->modify('+30 minutes');
        $opens = $start->modify(sprintf('-%d minutes', $this->openBefore));
        $closes = $end->modify(sprintf('+%d minutes', $this->closeAfter));

        $room = $this->rooms->findOneBySubject($subject->getVisioKey());
        if (null === $room) {
            $room = new Room($subject->getVisioKey(), $subject->getVisioHost(), $subject->getVisioGuest(), $opens, $closes);
            $this->entityManager->persist($room);
        } else {
            $room->reschedule($opens, $closes)->setHost($subject->getVisioHost())->setGuest($subject->getVisioGuest());
        }
        $this->entityManager->flush();

        return $room;
    }

    /** A participant, while it is open. */
    public function canEnter(Room $room, ?User $user, ?\DateTimeInterface $now = null): bool
    {
        return $room->isParticipant($user) && $room->isOpen($now);
    }

    /** "I am here": the waiting room and the agenda read it. */
    public function heartbeat(Room $room, User $user): void
    {
        $room->seen($user);
        $this->entityManager->flush();
    }

    public function close(Room $room): void
    {
        $room->end();
        $this->entityManager->flush();
        $this->signals->purgeRoom($room);
    }

    /** Closed rooms' handshakes go: health:purge and office:visio:purge call it. */
    public function purge(?\DateTimeInterface $now = null): int
    {
        $count = 0;
        foreach ($this->rooms->findClosedBefore($now ?? new \DateTimeImmutable()) as $room) {
            $count += $this->signals->purgeRoom($room);
        }

        return $count;
    }
}
