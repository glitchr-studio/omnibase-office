<?php

namespace Base\Office\Entity\Booking;

use Base\Office\Entity\Member;
use Base\Office\Entity\Office;
use Base\Office\Repository\Booking\ScheduleRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * When a member receives, week after week: Monday 8:30-12:00 at the main
 * office, for these appointment types (none listed: all of theirs), valid
 * from - until (a summer timetable, a replacement). No office: the time is
 * for home visits or remote appointments.
 */
#[ORM\Entity(repositoryClass: ScheduleRepository::class)]
#[ORM\Table(name: 'office_schedule')]
#[ORM\Index(columns: ['dayOfWeek'], name: 'office_schedule_day_idx')]
class Schedule
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    protected $id;

    #[ORM\ManyToOne(targetEntity: Member::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    protected ?Member $member = null;

    #[ORM\ManyToOne(targetEntity: Office::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    protected ?Office $office = null;

    /** ISO day: 1 Monday to 7 Sunday. */
    #[ORM\Column(type: 'smallint')]
    protected int $dayOfWeek = 1;

    #[ORM\Column(length: 5)]
    protected string $startsAt = '08:30';

    #[ORM\Column(length: 5)]
    protected string $endsAt = '12:00';

    /** @var Collection<int, AppointmentType> */
    #[ORM\ManyToMany(targetEntity: AppointmentType::class)]
    #[ORM\JoinTable(name: 'office_schedule_type')]
    protected Collection $appointmentTypes;

    #[ORM\Column(type: 'date_immutable', nullable: true)]
    protected ?\DateTimeImmutable $validFrom = null;

    #[ORM\Column(type: 'date_immutable', nullable: true)]
    protected ?\DateTimeImmutable $validUntil = null;

    public function __construct(?Member $member = null, int $dayOfWeek = 1, string $startsAt = '08:30', string $endsAt = '12:00', ?Office $office = null)
    {
        $this->member = $member;
        $this->dayOfWeek = $dayOfWeek;
        $this->startsAt = $startsAt;
        $this->endsAt = $endsAt;
        $this->office = $office;
        $this->appointmentTypes = new ArrayCollection();
    }

    public function __toString(): string
    {
        return sprintf('%s · %d · %s-%s', $this->member, $this->dayOfWeek, $this->startsAt, $this->endsAt);
    }

    public function getId(): ?int { return $this->id; }
    public function getMember(): ?Member { return $this->member; }
    public function setMember(?Member $member): self { $this->member = $member; return $this; }
    public function getOffice(): ?Office { return $this->office; }
    public function setOffice(?Office $office): self { $this->office = $office; return $this; }
    public function getDayOfWeek(): int { return $this->dayOfWeek; }
    public function setDayOfWeek(?int $day): self { $this->dayOfWeek = max(1, min(7, (int) $day)); return $this; }
    public function getStartsAt(): string { return $this->startsAt; }
    public function setStartsAt(?string $time): self { $this->startsAt = substr((string) $time, 0, 5); return $this; }
    public function getEndsAt(): string { return $this->endsAt; }
    public function setEndsAt(?string $time): self { $this->endsAt = substr((string) $time, 0, 5); return $this; }
    /** @return Collection<int, AppointmentType> */
    public function getAppointmentTypes(): Collection { return $this->appointmentTypes; }
    public function addAppointmentType(AppointmentType $type): self { if (!$this->appointmentTypes->contains($type)) { $this->appointmentTypes->add($type); } return $this; }
    public function removeAppointmentType(AppointmentType $type): self { $this->appointmentTypes->removeElement($type); return $this; }
    public function getValidFrom(): ?\DateTimeImmutable { return $this->validFrom; }
    public function setValidFrom(?\DateTimeInterface $date): self { $this->validFrom = $date ? \DateTimeImmutable::createFromInterface($date) : null; return $this; }
    public function getValidUntil(): ?\DateTimeImmutable { return $this->validUntil; }
    public function setValidUntil(?\DateTimeInterface $date): self { $this->validUntil = $date ? \DateTimeImmutable::createFromInterface($date) : null; return $this; }

    /** Whether it holds that type: none listed means every type. */
    public function accepts(AppointmentType $type): bool
    {
        return 0 === $this->appointmentTypes->count() || $this->appointmentTypes->contains($type);
    }

    /** Whether it applies on that day (its weekday, within its validity). */
    public function appliesOn(\DateTimeInterface $day): bool
    {
        if ((int) $day->format('N') !== $this->dayOfWeek) {
            return false;
        }
        $date = $day->format('Y-m-d');
        if ($this->validFrom && $date < $this->validFrom->format('Y-m-d')) {
            return false;
        }

        return !($this->validUntil && $date > $this->validUntil->format('Y-m-d'));
    }
}
