<?php

namespace Base\Office\Entity\Booking;

use Base\Office\Entity\Member;
use Base\Office\Entity\Office;
use Base\Office\Repository\Booking\ServiceAreaRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * Where the office goes to its clients: a list of postcodes and towns, or a
 * radius around one of its offices. A home visit outside every area is
 * refused before anyone has to call back.
 */
#[ORM\Entity(repositoryClass: ServiceAreaRepository::class)]
#[ORM\Table(name: 'office_service_area')]
class ServiceArea
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    protected $id;

    #[ORM\Column(length: 160)]
    protected string $name = '';

    /** @var list<string> */
    #[ORM\Column(type: 'json')]
    protected array $postalCodes = [];

    /** @var list<string> towns, as people write them */
    #[ORM\Column(type: 'json')]
    protected array $towns = [];

    #[ORM\ManyToOne(targetEntity: Office::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    protected ?Office $office = null;

    #[ORM\Column(type: 'float', nullable: true)]
    protected ?float $radiusKm = null;

    /** @var Collection<int, Member> who covers it (none: the whole team) */
    #[ORM\ManyToMany(targetEntity: Member::class)]
    #[ORM\JoinTable(name: 'office_service_area_member')]
    protected Collection $members;

    #[ORM\Column(type: 'boolean')]
    protected bool $active = true;

    public function __construct(string $name = '', array $postalCodes = [], array $towns = [])
    {
        $this->name = $name;
        $this->setPostalCodes($postalCodes);
        $this->setTowns($towns);
        $this->members = new ArrayCollection();
    }

    public function __toString(): string
    {
        return $this->name;
    }

    public function getId(): ?int { return $this->id; }
    public function getName(): string { return $this->name; }
    public function setName(?string $name): self { $this->name = trim((string) $name); return $this; }
    /** @return list<string> */
    public function getPostalCodes(): array { return $this->postalCodes; }
    public function setPostalCodes(?array $codes): self { $this->postalCodes = array_values(array_unique(array_filter(array_map(static fn ($c) => preg_replace('/\s+/', '', (string) $c), (array) $codes)))); return $this; }
    /** @return list<string> */
    public function getTowns(): array { return $this->towns; }
    public function setTowns(?array $towns): self { $this->towns = array_values(array_unique(array_filter(array_map('trim', array_map('strval', (array) $towns))))); return $this; }
    /** The back office edits both lists as one line each. */
    public function getPostalCodesText(): string { return implode(', ', $this->postalCodes); }
    public function setPostalCodesText(?string $text): self { return $this->setPostalCodes(preg_split('/[\s,;]+/', (string) $text) ?: []); }
    public function getTownsText(): string { return implode(', ', $this->towns); }
    public function setTownsText(?string $text): self { return $this->setTowns(preg_split('/[,;\n]+/', (string) $text) ?: []); }
    public function getOffice(): ?Office { return $this->office; }
    public function setOffice(?Office $office): self { $this->office = $office; return $this; }
    public function getRadiusKm(): ?float { return $this->radiusKm; }
    public function setRadiusKm(?float $radius): self { $this->radiusKm = $radius; return $this; }
    /** @return Collection<int, Member> */
    public function getMembers(): Collection { return $this->members; }
    public function addMember(Member $member): self { if (!$this->members->contains($member)) { $this->members->add($member); } return $this; }
    public function removeMember(Member $member): self { $this->members->removeElement($member); return $this; }
    public function isActive(): bool { return $this->active; }
    public function setActive(bool $active): self { $this->active = $active; return $this; }

    /**
     * Whether an address is inside: its postcode listed, its town listed
     * (accents and case aside), or within the radius of the office.
     */
    public function contains(?string $postalCode, ?string $town = null, ?float $latitude = null, ?float $longitude = null): bool
    {
        $postalCode = preg_replace('/\s+/', '', (string) $postalCode);
        if ('' !== $postalCode && \in_array($postalCode, $this->postalCodes, true)) {
            return true;
        }
        if (null !== $town && '' !== trim($town)) {
            $needle = Office::slugify($town);
            foreach ($this->towns as $listed) {
                if (Office::slugify($listed) === $needle) {
                    return true;
                }
            }
        }
        if (null !== $this->radiusKm && null !== $latitude && null !== $longitude && null !== $this->office?->getLatitude() && null !== $this->office->getLongitude()) {
            return self::distanceKm($latitude, $longitude, $this->office->getLatitude(), $this->office->getLongitude()) <= $this->radiusKm;
        }

        return false;
    }

    /** Great-circle distance (haversine). */
    public static function distanceKm(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;

        return 6371.0 * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}
