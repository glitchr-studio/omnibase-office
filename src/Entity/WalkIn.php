<?php

namespace Base\Office\Entity;

use Base\Office\Repository\WalkInRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Hours when the practice receives without an appointment: the nurses'
 * blood tests from 7:30 to 8:30, a walk-in consultation on Saturday
 * morning. Shown with the opening hours; not bookable.
 */
#[ORM\Entity(repositoryClass: WalkInRepository::class)]
#[ORM\Table(name: 'office_walk_in')]
class WalkIn
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    protected $id;

    /** "Prises de sang sans rendez-vous" */
    #[ORM\Column(length: 160)]
    protected string $label = '';

    /** @var list<int> ISO days, 1 Monday to 7 Sunday */
    #[ORM\Column(type: 'json')]
    protected array $days = [];

    #[ORM\Column(length: 5)]
    protected string $opensAt = '07:30';

    #[ORM\Column(length: 5)]
    protected string $closesAt = '08:30';

    #[ORM\ManyToOne(targetEntity: Office::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    protected ?Office $office = null;

    #[ORM\ManyToOne(targetEntity: Member::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    protected ?Member $member = null;

    #[ORM\Column(type: 'text', nullable: true)]
    protected ?string $notes = null;

    #[ORM\Column(type: 'boolean')]
    protected bool $active = true;

    public function __construct(string $label = '', array $days = [], string $opensAt = '07:30', string $closesAt = '08:30')
    {
        $this->label = $label;
        $this->setDays($days);
        $this->opensAt = $opensAt;
        $this->closesAt = $closesAt;
    }

    public function __toString(): string
    {
        return $this->label;
    }

    public function getId(): ?int { return $this->id; }
    public function getLabel(): string { return $this->label; }
    public function setLabel(?string $label): self { $this->label = trim((string) $label); return $this; }
    /** @return list<int> */
    public function getDays(): array { return $this->days; }
    public function setDays(?array $days): self { $this->days = array_values(array_unique(array_map('intval', array_filter((array) $days, static fn ($d) => (int) $d >= 1 && (int) $d <= 7)))); sort($this->days); return $this; }
    public function getOpensAt(): string { return $this->opensAt; }
    public function setOpensAt(?string $time): self { $this->opensAt = substr((string) $time, 0, 5); return $this; }
    public function getClosesAt(): string { return $this->closesAt; }
    public function setClosesAt(?string $time): self { $this->closesAt = substr((string) $time, 0, 5); return $this; }
    public function getOffice(): ?Office { return $this->office; }
    public function setOffice(?Office $office): self { $this->office = $office; return $this; }
    public function getMember(): ?Member { return $this->member; }
    public function setMember(?Member $member): self { $this->member = $member; return $this; }
    public function getNotes(): ?string { return $this->notes; }
    public function setNotes(?string $notes): self { $this->notes = $notes ?: null; return $this; }
    public function isActive(): bool { return $this->active; }
    public function setActive(bool $active): self { $this->active = $active; return $this; }

    public function isOn(int $isoDay): bool
    {
        return \in_array($isoDay, $this->days, true);
    }
}
