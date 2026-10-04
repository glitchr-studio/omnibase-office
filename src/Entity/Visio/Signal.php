<?php

namespace Base\Office\Entity\Visio;

use App\Entity\User;
use Base\Office\Repository\Visio\SignalRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * One message of the WebRTC handshake, from one side of a room to the
 * other: an offer, an answer, an ICE candidate, a hang-up. Read by the
 * other side's short polling, purged when the room closes.
 */
#[ORM\Entity(repositoryClass: SignalRepository::class)]
#[ORM\Table(name: 'office_visio_signal')]
#[ORM\Index(columns: ['room_id', 'id'], name: 'office_visio_signal_room_idx')]
class Signal
{
    public const TYPES = ['offer', 'answer', 'candidate', 'bye', 'hello'];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    protected $id;

    #[ORM\ManyToOne(targetEntity: Room::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    protected Room $room;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    protected ?User $sender;

    #[ORM\Column(length: 16)]
    protected string $type;

    #[ORM\Column(type: 'text')]
    protected string $payload;

    #[ORM\Column(type: 'utc_datetime_immutable')]
    protected \DateTimeImmutable $createdAt;

    public function __construct(Room $room, ?User $sender, string $type, string $payload)
    {
        $this->room = $room;
        $this->sender = $sender;
        $this->type = $type;
        $this->payload = $payload;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getRoom(): Room { return $this->room; }
    public function getSender(): ?User { return $this->sender; }
    public function getType(): string { return $this->type; }
    public function getPayload(): string { return $this->payload; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
