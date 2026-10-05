<?php

namespace Base\Office\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * A field of practice (family, real estate, labour...): what it covers,
 * what is done in it, who of the team follows it. Information for the
 * public, whose wording a regime checks (Base\Office\Guard\Wording).
 *
 * A mapped superclass: each regime has its own entity and its own table,
 * and names what is done in the field its own way - a notary's deeds, a
 * lawyer's matters:
 *
 *     #[ORM\Entity(repositoryClass: AreaRepository::class)]
 *     #[ORM\Table(name: 'notary_area')]
 *     class Area extends \Base\Office\Entity\Area
 *     {
 *         #[ORM\Column(type: 'json')]
 *         protected array $deeds = [];
 *
 *         #[ORM\ManyToMany(targetEntity: Member::class)]
 *         #[ORM\JoinTable(name: 'notary_area_member')]
 *         protected Collection $members;
 *
 *         public function getItems(): array { return $this->deeds; }
 *         public function setItems(?array $items): static { $this->deeds = self::lines($items); return $this; }
 *     }
 *
 * The list and the members are mapped by the entity (a column and a join
 * table of its own names); everything else is here. The repository:
 * Base\Office\Repository\AreaRepository.
 */
#[ORM\MappedSuperclass]
abstract class Area
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    protected $id;

    #[ORM\Column(length: 160)]
    protected string $name = '';

    #[ORM\Column(length: 160, unique: true)]
    protected string $slug = '';

    /** One or two sentences, for the list. */
    #[ORM\Column(type: 'text', nullable: true)]
    protected ?string $summary = null;

    /** The page's text: paragraphs separated by an empty line. */
    #[ORM\Column(type: 'text', nullable: true)]
    protected ?string $body = null;

    /** @var Collection<int, Member> who of the team follows it - mapped by the entity, with its own join table */
    protected Collection $members;

    #[ORM\Column(type: 'boolean')]
    protected bool $active = true;

    #[ORM\Column(type: 'integer')]
    protected int $position = 0;

    public function __construct(string $name = '', ?string $slug = null)
    {
        $this->members = new ArrayCollection();
        $this->name = trim($name);
        $this->slug = Office::slugify($slug ?? $name);
    }

    public function __toString(): string
    {
        return $this->name;
    }

    /** @return list<string> what is done in that field: a notary's deeds, a lawyer's matters */
    abstract public function getItems(): array;

    abstract public function setItems(?array $items): static;

    /** A list as the column holds it: trimmed, without empty entries. */
    protected static function lines(?array $items): array
    {
        return array_values(array_filter(array_map('trim', (array) $items)));
    }

    /** The list, one a line: what the back office's form edits. */
    public function getItemsText(): string { return implode("\n", $this->getItems()); }
    public function setItemsText(?string $text): static { return $this->setItems(preg_split('/\R/', (string) $text) ?: []); }

    public function getId(): ?int { return $this->id; }
    public function getName(): string { return $this->name; }
    public function setName(?string $name): static { $this->name = trim((string) $name); if ('' === $this->slug) { $this->slug = Office::slugify($this->name); } return $this; }
    public function getSlug(): string { return $this->slug; }
    public function setSlug(?string $slug): static { $this->slug = Office::slugify((string) $slug); return $this; }
    public function getSummary(): ?string { return $this->summary; }
    public function setSummary(?string $summary): static { $this->summary = $summary ?: null; return $this; }
    public function getBody(): ?string { return $this->body; }
    public function setBody(?string $body): static { $this->body = $body ?: null; return $this; }
    /** @return Collection<int, Member> */
    public function getMembers(): Collection { return $this->members; }
    public function addMember(Member $member): static { if (!$this->members->contains($member)) { $this->members->add($member); } return $this; }
    public function removeMember(Member $member): static { $this->members->removeElement($member); return $this; }
    public function isActive(): bool { return $this->active; }
    public function setActive(bool $active): static { $this->active = $active; return $this; }
    public function getPosition(): int { return $this->position; }
    public function setPosition(?int $position): static { $this->position = (int) $position; return $this; }

    /** @return list<string> the body's paragraphs */
    public function getParagraphs(): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/\R{2,}/', (string) $this->body) ?: [])));
    }

    /** Everything written for the public, for the wording check. */
    public function getPublicText(): string
    {
        return implode("\n", array_filter([$this->name, $this->summary, $this->body, implode("\n", $this->getItems())]));
    }
}
