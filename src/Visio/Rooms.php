<?php

namespace Base\Office\Visio;

use App\Entity\User;
use Base\Office\Entity\Visio\Room;
use Base\Office\Repository\MemberRepository;
use Base\Office\Repository\Visio\RoomRepository;
use Base\Office\Repository\Visio\SignalRepository;
use Doctrine\ORM\EntityManagerInterface;
use Omnimeet\Exception\OmnimeetException;
use Omnimeet\Model\Access;
use Omnimeet\Model\Meeting;
use Omnimeet\Model\Participant;
use Omnimeet\Model\Role;
use Omnimeet\Model\Status;
use Omnimeet\Request\Close;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The room of a subject: made the first time it is asked for, its times
 * kept in step with the subject's (an appointment moved), opened
 * office.visio.open_before minutes before and closed close_after minutes
 * after the end. Closing purges the handshake.
 *
 * What carries the call is a gateway of glitchr/omnimeet: the room is
 * opened on office.visio.gateway and keeps its reference there; entering
 * asks that gateway how (access()). The gateway is told as little as it
 * needs - the room's own token for a key, never what the room is for, and
 * of the people a role and an opaque identifier; the member's name unless
 * office.visio.names says none, the client's never.
 */
class Rooms
{
    public function __construct(
        private readonly RoomRepository $rooms,
        private readonly SignalRepository $signals,
        private readonly EntityManagerInterface $entityManager,
        private readonly Gateways $gateways,
        private readonly MemberRepository $members,
        #[Autowire('%office.visio.open_before%')] private readonly int $openBefore = 10,
        #[Autowire('%office.visio.close_after%')] private readonly int $closeAfter = 30,
        #[Autowire('%office.visio.names%')] private readonly string $names = 'host',
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
        $this->openOnGateway($room);
        $this->entityManager->flush();

        return $room;
    }

    /** A participant, while it is open. */
    public function canEnter(Room $room, ?User $user, ?\DateTimeInterface $now = null): bool
    {
        return $room->isParticipant($user) && $room->isOpen($now);
    }

    /**
     * How this participant enters: what the room's gateway answers to Join -
     * the direct engine and its ICE servers, a provider's frame, or an
     * address. Asked each time: it is made for this person, now (temporary
     * credentials, a token). Whether they may enter is canEnter()'s to say,
     * before.
     *
     * @throws OmnimeetException when the gateway is misconfigured or refuses
     */
    public function access(Room $room, User $user, ?string $locale = null): Access
    {
        if ($this->openOnGateway($room)) {
            $this->entityManager->flush();
        }

        return $this->gateways->get($room->getGateway())->join($this->meeting($room), $this->participant($room, $user, $locale));
    }

    /** The room's name on its gateway - where omnimeet/direct keeps its handshake - opening it there when it is not yet. */
    public function reference(Room $room): string
    {
        if ($this->openOnGateway($room)) {
            $this->entityManager->flush();
        }

        return (string) $room->getReference();
    }

    /** The two are talking to each other (the handshake's answer was given). */
    public function connected(Room $room): void
    {
        if (null === $room->getConnectedAt()) {
            $room->markConnected();
            $this->entityManager->flush();
        }
    }

    /** Whether the room's call goes from browser to browser, its handshake relayed by the site. */
    public function isDirect(Room $room): bool
    {
        return $this->gateways->isDirect($room->getGateway() ?? $this->gateways->name());
    }

    /** The room as glitchr/omnimeet knows a meeting: the token for a key, the window it is open in, no title. */
    public function meeting(Room $room): Meeting
    {
        return new Meeting(
            $room->getToken(),
            $room->getOpensAt(),
            $room->getClosesAt(),
            null,
            $room->getReference(),
            null !== $room->getEndedAt() ? Status::ENDED : (null !== $room->getConnectedAt() ? Status::LIVE : ($room->isOpenedOnGateway() ? Status::OPEN : Status::PLANNED)),
        );
    }

    /** Someone of the room as a gateway sees them: a role, "u<id>", and for the host the member's name (office.visio.names). */
    public function participant(Room $room, User $user, ?string $locale = null): Participant
    {
        $host = $room->isHost($user);
        // The member's name, as the site's own pages give it; the client's is never told.
        $name = $host && 'host' === $this->names ? $this->members->findOneByUser($user)?->getDisplayName() : null;

        return new Participant(self::participantId($user), $host ? Role::HOST : Role::GUEST, $name ?: null, $locale);
    }

    /** The opaque identifier a gateway (and the handshake's storage) knows an account by. */
    public static function participantId(User $user): string
    {
        return 'u'.$user->getId();
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
        // The gateway closes its side when it can (the direct one purges the handshake; a provider whose
        // documentation shows no way to end a meeting from the server is left to its participants' pages).
        if ($room->isOpenedOnGateway() && $this->gateways->has($room->getGateway())) {
            try {
                $gateway = $this->gateways->get($room->getGateway());
                if ($gateway->supports(Close::class)) {
                    $gateway->close($this->meeting($room));
                }
            } catch (OmnimeetException) {
                // The room is closed here whatever the gateway says: nobody enters any more.
            }
        }
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

    /**
     * Opens the room on a gateway when it is not yet: on the configured one.
     * A room nobody has entered follows the configuration (the practice
     * changed gateway: the appointments to come change with it); a room
     * someone has been in stays on the gateway it was opened on, as long as
     * that gateway is still configured.
     *
     * @return bool whether something changed
     */
    private function openOnGateway(Room $room): bool
    {
        $name = $this->gateways->name();
        if ($room->isOpenedOnGateway() && $this->gateways->has($room->getGateway())) {
            $entered = null !== $room->getHostSeenAt() || null !== $room->getGuestSeenAt() || null !== $room->getConnectedAt() || null !== $room->getEndedAt();
            if ($entered || $room->getGateway() === $name) {
                return false;
            }
        }
        $meeting = $this->gateways->get($name)->open(new Meeting($room->getToken(), $room->getOpensAt(), $room->getClosesAt()));
        $room->openedOn($name, $meeting->reference());

        return true;
    }
}
