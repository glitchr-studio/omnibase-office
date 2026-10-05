<?php

namespace Base\Office\Entity\Visio;

use App\Entity\User;
use Base\Office\Repository\Visio\RoomRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * A video room: the host (the member) and the guest (the client), opened a
 * little before its subject's start and closed a while after its end. The
 * guest waits in it until the host joins. What carries the call is a
 * gateway of glitchr/omnimeet - by default from browser to browser
 * (omnimeet/direct): the room then only relays their handshake; the room
 * keeps which gateway opened it and its reference there. Who may enter,
 * who is there, when it ends: all of that is judged here, whatever the
 * gateway.
 */
#[ORM\Entity(repositoryClass: RoomRepository::class)]
#[ORM\Table(name: 'office_visio_room')]
#[ORM\UniqueConstraint(name: 'office_visio_room_token', columns: ['token'])]
#[ORM\UniqueConstraint(name: 'office_visio_room_subject', columns: ['subjectKey'])]
#[ORM\Index(name: 'office_visio_room_reference', columns: ['reference'])]
class Room
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    protected $id;

    /** The room's address: random, unguessable. */
    #[ORM\Column(length: 43)]
    protected string $token;

    /** What it is for: "appointment:12". */
    #[ORM\Column(length: 120)]
    protected string $subjectKey;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    protected ?User $host = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    protected ?User $guest = null;

    #[ORM\Column(type: 'utc_datetime_immutable')]
    protected \DateTimeImmutable $opensAt;

    #[ORM\Column(type: 'utc_datetime_immutable')]
    protected \DateTimeImmutable $closesAt;

    /** Last time each side said it was there (the waiting room, the agenda's badge). */
    #[ORM\Column(type: 'utc_datetime_immutable', nullable: true)]
    protected ?\DateTimeImmutable $hostSeenAt = null;

    #[ORM\Column(type: 'utc_datetime_immutable', nullable: true)]
    protected ?\DateTimeImmutable $guestSeenAt = null;

    #[ORM\Column(type: 'utc_datetime_immutable', nullable: true)]
    protected ?\DateTimeImmutable $guestWaitingSince = null;

    #[ORM\Column(type: 'utc_datetime_immutable', nullable: true)]
    protected ?\DateTimeImmutable $connectedAt = null;

    #[ORM\Column(type: 'utc_datetime_immutable', nullable: true)]
    protected ?\DateTimeImmutable $endedAt = null;

    #[ORM\Column(type: 'utc_datetime_immutable')]
    protected \DateTimeImmutable $createdAt;

    /** The gateway that opened it (office.visio.gateway at that moment): "direct", "jitsi"... Null: not opened on one yet. */
    #[ORM\Column(length: 64, nullable: true)]
    protected ?string $gateway = null;

    /** Its name on that gateway: what glitchr/omnimeet's Open answered, kept to let people in and to close. */
    #[ORM\Column(length: 255, nullable: true)]
    protected ?string $reference = null;

    public function __construct(string $subjectKey, ?User $host, ?User $guest, \DateTimeInterface $opensAt, \DateTimeInterface $closesAt, ?string $token = null)
    {
        $this->subjectKey = $subjectKey;
        $this->host = $host;
        $this->guest = $guest;
        $this->opensAt = \DateTimeImmutable::createFromInterface($opensAt);
        $this->closesAt = \DateTimeImmutable::createFromInterface($closesAt);
        $this->token = $token ?? rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getToken(): string { return $this->token; }
    public function getSubjectKey(): string { return $this->subjectKey; }
    public function getHost(): ?User { return $this->host; }
    public function setHost(?User $host): self { $this->host = $host; return $this; }
    public function getGuest(): ?User { return $this->guest; }
    public function setGuest(?User $guest): self { $this->guest = $guest; return $this; }
    public function getOpensAt(): \DateTimeImmutable { return $this->opensAt; }
    public function getClosesAt(): \DateTimeImmutable { return $this->closesAt; }
    public function reschedule(\DateTimeInterface $opensAt, \DateTimeInterface $closesAt): self
    {
        $this->opensAt = \DateTimeImmutable::createFromInterface($opensAt);
        $this->closesAt = \DateTimeImmutable::createFromInterface($closesAt);

        return $this;
    }
    public function getHostSeenAt(): ?\DateTimeImmutable { return $this->hostSeenAt; }
    public function getGuestSeenAt(): ?\DateTimeImmutable { return $this->guestSeenAt; }
    public function getGuestWaitingSince(): ?\DateTimeImmutable { return $this->guestWaitingSince; }
    public function getConnectedAt(): ?\DateTimeImmutable { return $this->connectedAt; }
    public function getEndedAt(): ?\DateTimeImmutable { return $this->endedAt; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getGateway(): ?string { return $this->gateway; }
    public function getReference(): ?string { return $this->reference; }

    /** Opened on a gateway: its name, and the room's reference there. */
    public function openedOn(string $gateway, string $reference): self
    {
        $this->gateway = $gateway;
        $this->reference = $reference;

        return $this;
    }

    public function isOpenedOnGateway(): bool
    {
        return null !== $this->gateway && null !== $this->reference;
    }

    public function isHost(?User $user): bool
    {
        return null !== $user && null !== $this->host && $this->host->getId() === $user->getId();
    }

    public function isGuest(?User $user): bool
    {
        return null !== $user && null !== $this->guest && $this->guest->getId() === $user->getId();
    }

    public function isParticipant(?User $user): bool
    {
        return $this->isHost($user) || $this->isGuest($user);
    }

    public function isOpen(?\DateTimeInterface $now = null): bool
    {
        $now ??= new \DateTimeImmutable();

        return null === $this->endedAt && $now >= $this->opensAt && $now <= $this->closesAt;
    }

    /** Someone said "I am here" in the last $seconds. */
    public function seen(User $user, ?\DateTimeInterface $now = null): self
    {
        $now = $now ? \DateTimeImmutable::createFromInterface($now) : new \DateTimeImmutable();
        if ($this->isHost($user)) {
            $this->hostSeenAt = $now;
        } elseif ($this->isGuest($user)) {
            $this->guestSeenAt = $now;
            $this->guestWaitingSince ??= $now;
        }

        return $this;
    }

    public function isHostPresent(int $seconds = 20, ?\DateTimeInterface $now = null): bool
    {
        return null !== $this->hostSeenAt && $this->hostSeenAt->getTimestamp() >= ($now ?? new \DateTimeImmutable())->getTimestamp() - $seconds;
    }

    public function isGuestPresent(int $seconds = 20, ?\DateTimeInterface $now = null): bool
    {
        return null !== $this->guestSeenAt && $this->guestSeenAt->getTimestamp() >= ($now ?? new \DateTimeImmutable())->getTimestamp() - $seconds;
    }

    /** The guest is there and the host is not: the agenda says "in the waiting room". */
    public function isGuestWaiting(int $seconds = 20, ?\DateTimeInterface $now = null): bool
    {
        return $this->isGuestPresent($seconds, $now) && !$this->isHostPresent($seconds, $now) && null === $this->endedAt;
    }

    public function markConnected(): self { $this->connectedAt ??= new \DateTimeImmutable(); return $this; }
    public function end(): self { $this->endedAt ??= new \DateTimeImmutable(); return $this; }
}
