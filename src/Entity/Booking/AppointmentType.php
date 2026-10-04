<?php

namespace Base\Office\Entity\Booking;

use Base\Office\Entity\Member;
use Base\Office\Entity\Office;
use Base\Office\Enum\BookableBy;
use Base\Office\Enum\BookingMode;
use Base\Office\Enum\Channel;
use Base\Office\Repository\Booking\AppointmentTypeRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * What an appointment is for, as the client chooses it: "Consultation",
 * "Téléconsultation", "Prise de sang à domicile", "Signature d'acte". Its
 * duration and the buffer after it, how it takes place, who may book it
 * online, whether the office confirms it by hand, what to bring - and its
 * mode: a free slot picked by the client, or a request the office answers.
 */
#[ORM\Entity(repositoryClass: AppointmentTypeRepository::class)]
#[ORM\Table(name: 'office_appointment_type')]
class AppointmentType
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    protected $id;

    #[ORM\Column(length: 160)]
    protected string $name = '';

    #[ORM\Column(length: 160, unique: true)]
    protected string $slug = '';

    /** Minutes. */
    #[ORM\Column(type: 'integer')]
    protected int $duration = 20;

    /** Minutes kept free after it (notes, the next patient in). */
    #[ORM\Column(type: 'integer')]
    protected int $buffer = 0;

    #[ORM\Column(length: 16, enumType: Channel::class)]
    protected Channel $channel = Channel::IN_PERSON;

    #[ORM\Column(length: 16, enumType: BookableBy::class)]
    protected BookableBy $bookableBy = BookableBy::EVERYONE;

    #[ORM\Column(length: 16, enumType: BookingMode::class)]
    protected BookingMode $mode = BookingMode::SLOTS;

    /** The office confirms each one by hand: it stays REQUESTED until then. */
    #[ORM\Column(type: 'boolean')]
    protected bool $manualConfirmation = false;

    /** What to bring, how to prepare: shown at booking and in the confirmation. */
    #[ORM\Column(type: 'text', nullable: true)]
    protected ?string $instructions = null;

    /** As it should be printed: "25 €", "à partir de 60 €". Nothing is paid online. */
    #[ORM\Column(length: 80, nullable: true)]
    protected ?string $price = null;

    /** @var Collection<int, Member> who offers it */
    #[ORM\ManyToMany(targetEntity: Member::class)]
    #[ORM\JoinTable(name: 'office_appointment_type_member')]
    protected Collection $members;

    /** Where it takes place (in person); none: at any of the member's offices. */
    #[ORM\ManyToOne(targetEntity: Office::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    protected ?Office $office = null;

    #[ORM\Column(type: 'boolean')]
    protected bool $active = true;

    #[ORM\Column(type: 'integer')]
    protected int $position = 0;

    public function __construct(string $name = '', int $duration = 20, Channel $channel = Channel::IN_PERSON)
    {
        $this->name = $name;
        $this->slug = Office::slugify($name);
        $this->duration = $duration;
        $this->channel = $channel;
        $this->members = new ArrayCollection();
    }

    public function __toString(): string
    {
        return $this->name;
    }

    public function getId(): ?int { return $this->id; }
    public function getName(): string { return $this->name; }
    public function setName(?string $name): self { $this->name = trim((string) $name); return $this; }
    public function getSlug(): string { return $this->slug; }
    public function setSlug(?string $slug): self { $this->slug = Office::slugify((string) $slug); return $this; }
    public function getDuration(): int { return $this->duration; }
    public function setDuration(?int $duration): self { $this->duration = max(5, (int) $duration); return $this; }
    public function getBuffer(): int { return $this->buffer; }
    public function setBuffer(?int $buffer): self { $this->buffer = max(0, (int) $buffer); return $this; }
    public function getChannel(): Channel { return $this->channel; }
    public function setChannel(Channel|string $channel): self { $this->channel = $channel instanceof Channel ? $channel : Channel::from($channel); return $this; }
    public function getChannelValue(): string { return $this->channel->value; }
    public function setChannelValue(?string $value): self { return $this->setChannel($value ?: Channel::IN_PERSON->value); }
    public function getBookableBy(): BookableBy { return $this->bookableBy; }
    public function setBookableBy(BookableBy|string $by): self { $this->bookableBy = $by instanceof BookableBy ? $by : BookableBy::from($by); return $this; }
    public function getBookableByValue(): string { return $this->bookableBy->value; }
    public function setBookableByValue(?string $value): self { return $this->setBookableBy($value ?: BookableBy::EVERYONE->value); }
    public function getMode(): BookingMode { return $this->mode; }
    public function setMode(BookingMode|string $mode): self { $this->mode = $mode instanceof BookingMode ? $mode : BookingMode::from($mode); return $this; }
    public function getModeValue(): string { return $this->mode->value; }
    public function setModeValue(?string $value): self { return $this->setMode($value ?: BookingMode::SLOTS->value); }
    public function isRequest(): bool { return BookingMode::REQUEST === $this->mode; }
    public function isManualConfirmation(): bool { return $this->manualConfirmation; }
    public function setManualConfirmation(bool $manual): self { $this->manualConfirmation = $manual; return $this; }
    public function getInstructions(): ?string { return $this->instructions; }
    public function setInstructions(?string $instructions): self { $this->instructions = $instructions ?: null; return $this; }
    public function getPrice(): ?string { return $this->price; }
    public function setPrice(?string $price): self { $this->price = $price ?: null; return $this; }
    /** @return Collection<int, Member> */
    public function getMembers(): Collection { return $this->members; }
    public function addMember(Member $member): self { if (!$this->members->contains($member)) { $this->members->add($member); } return $this; }
    public function removeMember(Member $member): self { $this->members->removeElement($member); return $this; }
    public function isOfferedBy(Member $member): bool { return $this->members->contains($member); }
    public function getOffice(): ?Office { return $this->office; }
    public function setOffice(?Office $office): self { $this->office = $office; return $this; }
    public function isActive(): bool { return $this->active; }
    public function setActive(bool $active): self { $this->active = $active; return $this; }
    public function getPosition(): int { return $this->position; }
    public function setPosition(?int $position): self { $this->position = (int) $position; return $this; }

    /** Duration plus buffer: how long the slot keeps the member busy. */
    public function getSpan(): int
    {
        return $this->duration + $this->buffer;
    }
}
