<?php

namespace Base\Office\Entity\Booking;

use Base\Office\Entity\Member;
use Base\Office\Repository\Booking\AbsenceRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * A member away: holidays, a training, an afternoon off. Their slots are
 * gone for that time. The whole office closed is a special day of
 * omnibase's opening hours (Base\Entity\Hours\SpecialDay), not an absence.
 */
#[ORM\Entity(repositoryClass: AbsenceRepository::class)]
#[ORM\Table(name: 'office_absence')]
#[ORM\Index(columns: ['startsAt', 'endsAt'], name: 'office_absence_span_idx')]
class Absence
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    protected $id;

    #[ORM\ManyToOne(targetEntity: Member::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    protected ?Member $member = null;

    #[ORM\Column(type: 'utc_datetime_immutable')]
    protected \DateTimeImmutable $startsAt;

    #[ORM\Column(type: 'utc_datetime_immutable')]
    protected \DateTimeImmutable $endsAt;

    /** Internal: never shown to clients. */
    #[ORM\Column(length: 160, nullable: true)]
    protected ?string $reason = null;

    public function __construct(?Member $member = null, ?\DateTimeInterface $startsAt = null, ?\DateTimeInterface $endsAt = null, ?string $reason = null)
    {
        $this->member = $member;
        $this->startsAt = $startsAt ? \DateTimeImmutable::createFromInterface($startsAt) : new \DateTimeImmutable('today');
        $this->endsAt = $endsAt ? \DateTimeImmutable::createFromInterface($endsAt) : $this->startsAt->modify('+1 day');
        $this->reason = $reason;
    }

    public function __toString(): string
    {
        return sprintf('%s · %s → %s', $this->member, $this->startsAt->format('d/m/Y H:i'), $this->endsAt->format('d/m/Y H:i'));
    }

    public function getId(): ?int { return $this->id; }
    public function getMember(): ?Member { return $this->member; }
    public function setMember(?Member $member): self { $this->member = $member; return $this; }
    public function getStartsAt(): \DateTimeImmutable { return $this->startsAt; }
    public function setStartsAt(?\DateTimeInterface $at): self { if ($at) { $this->startsAt = \DateTimeImmutable::createFromInterface($at); } return $this; }
    public function getEndsAt(): \DateTimeImmutable { return $this->endsAt; }
    public function setEndsAt(?\DateTimeInterface $at): self { if ($at) { $this->endsAt = \DateTimeImmutable::createFromInterface($at); } return $this; }
    public function getReason(): ?string { return $this->reason; }
    public function setReason(?string $reason): self { $this->reason = $reason ?: null; return $this; }

    public function overlaps(\DateTimeInterface $start, \DateTimeInterface $end): bool
    {
        return $this->startsAt < $end && $this->endsAt > $start;
    }
}
