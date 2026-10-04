<?php

namespace Base\Office\Entity;

use App\Entity\User;
use Base\Office\Repository\MemberRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * Someone of the team, as the site presents them: a portrait, a title, the
 * languages they speak, a biography - and, for a regulated profession,
 * their number in its register (registryId: an RPPS number, a bar number),
 * read again by Base\Office\Service\MemberRegistry. The account they sign
 * in with is optional: a retired partner or a replacement may be on the
 * page without one. Appointments are booked with a Member.
 */
#[ORM\Entity(repositoryClass: MemberRepository::class)]
#[ORM\Table(name: 'office_member')]
class Member
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    protected $id;

    #[ORM\OneToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    protected ?User $user = null;

    #[ORM\Column(length: 160, unique: true)]
    protected string $slug = '';

    /** "Dr Claire Exemple", as printed. */
    #[ORM\Column(length: 160)]
    protected string $displayName = '';

    /** "Médecin généraliste", "Infirmière", "Notaire associé". */
    #[ORM\Column(length: 160, nullable: true)]
    protected ?string $title = null;

    /** The group the team page files them under: "Médecins", "Infirmières", "Secrétariat". */
    #[ORM\Column(length: 120, nullable: true)]
    protected ?string $category = null;

    /** A picture path or URL (omnibase's |picture filter reads it). */
    #[ORM\Column(length: 255, nullable: true)]
    protected ?string $portrait = null;

    /** @var list<string> ISO 639-1 */
    #[ORM\Column(type: 'json')]
    protected array $languages = ['fr'];

    #[ORM\Column(type: 'text', nullable: true)]
    protected ?string $biography = null;

    #[ORM\Column(length: 40, nullable: true)]
    protected ?string $registryId = null;

    #[ORM\Column(type: 'utc_datetime_immutable', nullable: true)]
    protected ?\DateTimeImmutable $registryCheckedAt = null;

    /** What the register said when it was last read: name, profession, workplaces, secure e-mails. */
    #[ORM\Column(type: 'json', nullable: true)]
    protected ?array $registryData = null;

    /** @var Collection<int, Office> */
    #[ORM\ManyToMany(targetEntity: Office::class)]
    #[ORM\JoinTable(name: 'office_member_office')]
    protected Collection $offices;

    /** The agenda's colour. */
    #[ORM\Column(length: 9, nullable: true)]
    protected ?string $color = null;

    #[ORM\Column(type: 'boolean')]
    protected bool $visible = true;

    #[ORM\Column(type: 'boolean')]
    protected bool $active = true;

    /** Appointments may be booked with them online (their types, their schedules). */
    #[ORM\Column(type: 'boolean')]
    protected bool $bookable = true;

    #[ORM\Column(type: 'integer')]
    protected int $position = 0;

    public function __construct(string $displayName = '', ?string $slug = null)
    {
        $this->displayName = $displayName;
        $this->slug = Office::slugify($slug ?? $displayName);
        $this->offices = new ArrayCollection();
    }

    public function __toString(): string
    {
        return $this->displayName;
    }

    public function getId(): ?int { return $this->id; }
    public function getUser(): ?User { return $this->user; }
    public function setUser(?User $user): self { $this->user = $user; return $this; }
    public function getSlug(): string { return $this->slug; }
    public function setSlug(?string $slug): self { $this->slug = Office::slugify((string) $slug); return $this; }
    public function getDisplayName(): string { return $this->displayName; }
    public function setDisplayName(?string $name): self { $this->displayName = trim((string) $name); return $this; }
    public function getTitle(): ?string { return $this->title; }
    public function setTitle(?string $title): self { $this->title = $title ?: null; return $this; }
    public function getCategory(): ?string { return $this->category; }
    public function setCategory(?string $category): self { $this->category = $category ?: null; return $this; }
    public function getPortrait(): ?string { return $this->portrait; }
    public function setPortrait(?string $portrait): self { $this->portrait = $portrait ?: null; return $this; }
    /** @return list<string> */
    public function getLanguages(): array { return $this->languages; }
    public function setLanguages(?array $languages): self { $this->languages = array_values(array_filter((array) $languages)); return $this; }
    public function getBiography(): ?string { return $this->biography; }
    public function setBiography(?string $biography): self { $this->biography = $biography ?: null; return $this; }
    public function getRegistryId(): ?string { return $this->registryId; }
    public function setRegistryId(?string $registryId): self { $this->registryId = $registryId ? preg_replace('/\s+/', '', $registryId) : null; return $this; }
    public function getRegistryCheckedAt(): ?\DateTimeImmutable { return $this->registryCheckedAt; }
    public function getRegistryData(): ?array { return $this->registryData; }
    public function setRegistryData(?array $data, ?\DateTimeImmutable $checkedAt = null): self
    {
        $this->registryData = $data;
        $this->registryCheckedAt = $checkedAt ?? new \DateTimeImmutable();

        return $this;
    }
    /** @return Collection<int, Office> */
    public function getOffices(): Collection { return $this->offices; }
    public function addOffice(Office $office): self { if (!$this->offices->contains($office)) { $this->offices->add($office); } return $this; }
    public function removeOffice(Office $office): self { $this->offices->removeElement($office); return $this; }
    public function getColor(): ?string { return $this->color; }
    public function setColor(?string $color): self { $this->color = $color ?: null; return $this; }
    public function isVisible(): bool { return $this->visible; }
    public function setVisible(bool $visible): self { $this->visible = $visible; return $this; }
    public function isActive(): bool { return $this->active; }
    public function setActive(bool $active): self { $this->active = $active; return $this; }
    public function isBookable(): bool { return $this->bookable && $this->active; }
    public function setBookable(bool $bookable): self { $this->bookable = $bookable; return $this; }
    public function getPosition(): int { return $this->position; }
    public function setPosition(?int $position): self { $this->position = (int) $position; return $this; }

    /** The initials shown when there is no portrait. */
    public function getInitials(): string
    {
        $name = (string) preg_replace('/^(dr|pr|me|mme|m)\.?\s+/i', '', $this->displayName);
        $initials = '';
        foreach (preg_split('/[\s\-]+/', $name) ?: [] as $part) {
            if ('' !== $part) {
                $initials .= mb_strtoupper(mb_substr($part, 0, 1));
            }
        }

        return mb_substr($initials, 0, 2);
    }
}
