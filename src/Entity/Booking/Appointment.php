<?php

namespace Base\Office\Entity\Booking;

use App\Entity\User;
use Base\Office\Entity\Member;
use Base\Office\Entity\Office;
use Base\Office\Enum\AppointmentSource;
use Base\Office\Enum\AppointmentStatus;
use Base\Office\Enum\Channel;
use Base\Office\Repository\Booking\AppointmentRepository;
use Base\Office\Visio\RoomSubjectInterface;
use Doctrine\ORM\Mapping as ORM;

/**
 * An appointment with a member: when (or not yet, for a request), what for,
 * where - an office, an address for a home visit, a video room - and who
 * for: the client's account, or a name taken on the phone, perhaps a
 * relative (a child, a parent cared for: `beneficiary`). The reason the
 * client typed is kept encrypted (reasonCipher: Base\Office\Share\Cipher);
 * no e-mail ever carries it.
 *
 * slotKey holds "member|start" while the appointment holds its slot and is
 * emptied when it no longer does: its unique index is what refuses a
 * second booking of the same slot, whatever two requests race.
 */
#[ORM\Entity(repositoryClass: AppointmentRepository::class)]
#[ORM\Table(name: 'office_appointment')]
#[ORM\Index(columns: ['startsAt'], name: 'office_appointment_start_idx')]
#[ORM\Index(columns: ['status', 'startsAt'], name: 'office_appointment_status_idx')]
#[ORM\UniqueConstraint(name: 'office_appointment_slot', columns: ['slotKey'])]
#[ORM\UniqueConstraint(name: 'office_appointment_cancel', columns: ['cancelTokenHash'])]
class Appointment implements RoomSubjectInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    protected $id;

    #[ORM\ManyToOne(targetEntity: Member::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    protected ?Member $member = null;

    #[ORM\ManyToOne(targetEntity: AppointmentType::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    protected ?AppointmentType $type = null;

    #[ORM\ManyToOne(targetEntity: Office::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    protected ?Office $office = null;

    /** A home visit: where. */
    #[ORM\Column(length: 255, nullable: true)]
    protected ?string $address = null;

    #[ORM\Column(type: 'utc_datetime_immutable', nullable: true)]
    protected ?\DateTimeImmutable $startsAt = null;

    #[ORM\Column(type: 'utc_datetime_immutable', nullable: true)]
    protected ?\DateTimeImmutable $endsAt = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    protected ?User $client = null;

    /** A client without an account (taken on the phone). */
    #[ORM\Column(length: 160, nullable: true)]
    protected ?string $clientName = null;

    #[ORM\Column(length: 180, nullable: true)]
    protected ?string $clientEmail = null;

    #[ORM\Column(length: 30, nullable: true)]
    protected ?string $clientPhone = null;

    /** Who it is for, when not the client: "Léa (enfant)". */
    #[ORM\Column(length: 160, nullable: true)]
    protected ?string $beneficiary = null;

    #[ORM\Column(length: 16, enumType: AppointmentStatus::class)]
    protected AppointmentStatus $status = AppointmentStatus::CONFIRMED;

    #[ORM\Column(length: 16, enumType: AppointmentSource::class)]
    protected AppointmentSource $source = AppointmentSource::ONLINE;

    /** The reason typed by the client, encrypted. */
    #[ORM\Column(type: 'text', nullable: true)]
    protected ?string $reasonCipher = null;

    #[ORM\Column(length: 120, nullable: true)]
    protected ?string $slotKey = null;

    /** SHA-256 of the cancellation token mailed to the client; the token itself is never kept. */
    #[ORM\Column(length: 64, nullable: true)]
    protected ?string $cancelTokenHash = null;

    /** @var list<int> the reminders already sent, in hours before */
    #[ORM\Column(type: 'json')]
    protected array $remindersSent = [];

    /** Appointments booked together (daily care for ten days) share it. */
    #[ORM\Column(length: 36, nullable: true)]
    protected ?string $series = null;

    /** What a regime keeps with it (health: the dependant's id). */
    #[ORM\Column(type: 'json')]
    protected array $meta = [];

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    protected ?User $createdBy = null;

    #[ORM\Column(type: 'utc_datetime_immutable')]
    protected \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'utc_datetime_immutable', nullable: true)]
    protected ?\DateTimeImmutable $cancelledAt = null;

    public function __construct(?Member $member = null, ?AppointmentType $type = null, ?\DateTimeInterface $startsAt = null)
    {
        $this->member = $member;
        $this->type = $type;
        $this->createdAt = new \DateTimeImmutable();
        if ($startsAt && $type) {
            $this->setStartsAt($startsAt);
        }
    }

    public function __toString(): string
    {
        return trim(($this->startsAt?->format('d/m/Y H:i') ?? '—').' · '.$this->member.' · '.$this->type);
    }

    public function getId(): ?int { return $this->id; }
    public function getMember(): ?Member { return $this->member; }
    public function setMember(?Member $member): self { $this->member = $member; $this->syncSlot(); return $this; }
    public function getType(): ?AppointmentType { return $this->type; }
    public function setType(?AppointmentType $type): self { $this->type = $type; return $this; }
    public function getOffice(): ?Office { return $this->office; }
    public function setOffice(?Office $office): self { $this->office = $office; return $this; }
    public function getAddress(): ?string { return $this->address; }
    public function setAddress(?string $address): self { $this->address = $address ?: null; return $this; }
    public function getStartsAt(): ?\DateTimeImmutable { return $this->startsAt; }
    public function getEndsAt(): ?\DateTimeImmutable { return $this->endsAt; }

    /** The start, and the end from the type's duration. */
    public function setStartsAt(?\DateTimeInterface $at): self
    {
        $this->startsAt = $at ? \DateTimeImmutable::createFromInterface($at) : null;
        $this->endsAt = $this->startsAt && $this->type ? $this->startsAt->modify(sprintf('+%d minutes', $this->type->getDuration())) : null;
        $this->syncSlot();

        return $this;
    }

    public function setEndsAt(?\DateTimeInterface $at): self { $this->endsAt = $at ? \DateTimeImmutable::createFromInterface($at) : null; return $this; }
    public function getClient(): ?User { return $this->client; }
    public function setClient(?User $client): self { $this->client = $client; return $this; }
    public function getClientName(): ?string { return $this->clientName ?? ($this->client ? (string) $this->client : null); }
    public function setClientName(?string $name): self { $this->clientName = $name ?: null; return $this; }
    public function getClientEmail(): ?string { return $this->clientEmail ?? $this->client?->getEmail(); }
    public function setClientEmail(?string $email): self { $this->clientEmail = $email ?: null; return $this; }
    public function getClientPhone(): ?string { return $this->clientPhone; }
    public function setClientPhone(?string $phone): self { $this->clientPhone = $phone ?: null; return $this; }
    public function getBeneficiary(): ?string { return $this->beneficiary; }
    public function setBeneficiary(?string $beneficiary): self { $this->beneficiary = $beneficiary ?: null; return $this; }
    public function getStatus(): AppointmentStatus { return $this->status; }
    public function setStatus(AppointmentStatus|string $status): self
    {
        $this->status = $status instanceof AppointmentStatus ? $status : AppointmentStatus::from($status);
        if (AppointmentStatus::CANCELLED === $this->status) {
            $this->cancelledAt ??= new \DateTimeImmutable();
        }
        $this->syncSlot();

        return $this;
    }
    public function getStatusValue(): string { return $this->status->value; }
    public function setStatusValue(?string $value): self { return $this->setStatus($value ?: AppointmentStatus::CONFIRMED->value); }
    public function isActive(): bool { return $this->status->isActive(); }
    public function isCancelled(): bool { return AppointmentStatus::CANCELLED === $this->status; }
    public function getSource(): AppointmentSource { return $this->source; }
    public function setSource(AppointmentSource|string $source): self { $this->source = $source instanceof AppointmentSource ? $source : AppointmentSource::from($source); return $this; }
    public function getReasonCipher(): ?string { return $this->reasonCipher; }
    public function setReasonCipher(?string $cipher): self { $this->reasonCipher = $cipher ?: null; return $this; }
    public function getSlotKey(): ?string { return $this->slotKey; }
    public function getCancelTokenHash(): ?string { return $this->cancelTokenHash; }
    public function setCancelTokenHash(?string $hash): self { $this->cancelTokenHash = $hash; return $this; }
    /** @return list<int> */
    public function getRemindersSent(): array { return $this->remindersSent; }
    public function markReminderSent(int $hours): self { $this->remindersSent = array_values(array_unique([...$this->remindersSent, $hours])); return $this; }
    public function hasReminderBeenSent(int $hours): bool { return \in_array($hours, $this->remindersSent, true); }
    public function resetReminders(): self { $this->remindersSent = []; return $this; }
    public function getSeries(): ?string { return $this->series; }
    public function setSeries(?string $series): self { $this->series = $series; return $this; }
    public function getMeta(): array { return $this->meta; }
    public function getMetaValue(string $key, mixed $default = null): mixed { return $this->meta[$key] ?? $default; }
    public function setMetaValue(string $key, mixed $value): self { $this->meta[$key] = $value; return $this; }
    public function getCreatedBy(): ?User { return $this->createdBy; }
    public function setCreatedBy(?User $user): self { $this->createdBy = $user; return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getCancelledAt(): ?\DateTimeImmutable { return $this->cancelledAt; }

    public function getChannel(): Channel
    {
        return $this->type?->getChannel() ?? Channel::IN_PERSON;
    }

    public function isVideo(): bool
    {
        return Channel::VIDEO === $this->getChannel();
    }

    public function isUpcoming(?\DateTimeInterface $now = null): bool
    {
        return null !== $this->startsAt && $this->startsAt > ($now ?? new \DateTimeImmutable());
    }

    /** The slot key: who and when, the same text for the same slot whatever the timezone it was written in. */
    public static function slotKeyFor(Member $member, \DateTimeInterface $start): string
    {
        return $member->getId().'|'.$start->getTimestamp();
    }

    private function syncSlot(): void
    {
        $this->slotKey = $this->member?->getId() && $this->startsAt && $this->status->isActive()
            ? self::slotKeyFor($this->member, $this->startsAt)
            : null;
    }

    // RoomSubjectInterface: a video appointment is the subject of its room.

    public function getVisioHost(): ?User
    {
        return $this->member?->getUser();
    }

    public function getVisioGuest(): ?User
    {
        return $this->client;
    }

    public function getVisioStartsAt(): ?\DateTimeImmutable
    {
        return $this->startsAt;
    }

    public function getVisioEndsAt(): ?\DateTimeImmutable
    {
        return $this->endsAt;
    }

    public function getVisioKey(): string
    {
        return 'appointment:'.$this->id;
    }
}
