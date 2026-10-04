<?php

namespace Base\Office\Entity;

use Base\Office\Repository\OfficeRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * A place where the practice receives: its address, how to reach it, what
 * it offers to someone in a wheelchair, its phones and languages. Most
 * practices have one; a second site, a branch, is a second Office. The
 * opening hours are omnibase's (Base\Service\OpeningHours), not a copy.
 */
#[ORM\Entity(repositoryClass: OfficeRepository::class)]
#[ORM\Table(name: 'office')]
class Office
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    protected $id;

    #[ORM\Column(length: 160)]
    protected string $name = '';

    #[ORM\Column(length: 160, unique: true)]
    protected string $slug = '';

    #[ORM\Column(length: 255, nullable: true)]
    protected ?string $street = null;

    #[ORM\Column(length: 16, nullable: true)]
    protected ?string $postalCode = null;

    #[ORM\Column(length: 120, nullable: true)]
    protected ?string $city = null;

    #[ORM\Column(length: 2)]
    protected string $country = 'FR';

    #[ORM\Column(type: 'float', nullable: true)]
    protected ?float $latitude = null;

    #[ORM\Column(type: 'float', nullable: true)]
    protected ?float $longitude = null;

    #[ORM\Column(length: 30, nullable: true)]
    protected ?string $phone = null;

    /** A second line: the nurses' mobile, an emergency line of the practice. */
    #[ORM\Column(length: 30, nullable: true)]
    protected ?string $secondaryPhone = null;

    #[ORM\Column(length: 120, nullable: true)]
    protected ?string $secondaryPhoneLabel = null;

    #[ORM\Column(length: 180, nullable: true)]
    protected ?string $email = null;

    /** How to get there: bus, tram, floor, door code. */
    #[ORM\Column(type: 'text', nullable: true)]
    protected ?string $access = null;

    #[ORM\Column(type: 'boolean')]
    protected bool $wheelchairAccessible = false;

    /** What the accessibility is: lift, ramp, adapted toilets, hearing loop. */
    #[ORM\Column(type: 'text', nullable: true)]
    protected ?string $accessibility = null;

    #[ORM\Column(type: 'text', nullable: true)]
    protected ?string $parking = null;

    /** @var list<string> ISO 639-1 */
    #[ORM\Column(type: 'json')]
    protected array $languages = ['fr'];

    #[ORM\Column(type: 'boolean')]
    protected bool $main = true;

    #[ORM\Column(type: 'integer')]
    protected int $position = 0;

    public function __construct(string $name = '', string $slug = '')
    {
        $this->name = $name;
        $this->slug = $slug ?: self::slugify($name);
    }

    public function __toString(): string
    {
        return $this->name;
    }

    public static function slugify(string $text): string
    {
        $text = transliterator_transliterate('Any-Latin; Latin-ASCII; Lower()', $text) ?: strtolower($text);

        return trim((string) preg_replace('/[^a-z0-9]+/', '-', $text), '-');
    }

    public function getId(): ?int { return $this->id; }
    public function getName(): string { return $this->name; }
    public function setName(?string $name): self { $this->name = trim((string) $name); return $this; }
    public function getSlug(): string { return $this->slug; }
    public function setSlug(?string $slug): self { $this->slug = self::slugify((string) $slug); return $this; }
    public function getStreet(): ?string { return $this->street; }
    public function setStreet(?string $street): self { $this->street = $street ?: null; return $this; }
    public function getPostalCode(): ?string { return $this->postalCode; }
    public function setPostalCode(?string $postalCode): self { $this->postalCode = $postalCode ?: null; return $this; }
    public function getCity(): ?string { return $this->city; }
    public function setCity(?string $city): self { $this->city = $city ?: null; return $this; }
    public function getCountry(): string { return $this->country; }
    public function setCountry(?string $country): self { $this->country = strtoupper($country ?: 'FR'); return $this; }
    public function getLatitude(): ?float { return $this->latitude; }
    public function setLatitude(?float $latitude): self { $this->latitude = $latitude; return $this; }
    public function getLongitude(): ?float { return $this->longitude; }
    public function setLongitude(?float $longitude): self { $this->longitude = $longitude; return $this; }
    public function getPhone(): ?string { return $this->phone; }
    public function setPhone(?string $phone): self { $this->phone = $phone ?: null; return $this; }
    public function getSecondaryPhone(): ?string { return $this->secondaryPhone; }
    public function setSecondaryPhone(?string $phone): self { $this->secondaryPhone = $phone ?: null; return $this; }
    public function getSecondaryPhoneLabel(): ?string { return $this->secondaryPhoneLabel; }
    public function setSecondaryPhoneLabel(?string $label): self { $this->secondaryPhoneLabel = $label ?: null; return $this; }
    public function getEmail(): ?string { return $this->email; }
    public function setEmail(?string $email): self { $this->email = $email ?: null; return $this; }
    public function getAccess(): ?string { return $this->access; }
    public function setAccess(?string $access): self { $this->access = $access ?: null; return $this; }
    public function isWheelchairAccessible(): bool { return $this->wheelchairAccessible; }
    public function setWheelchairAccessible(bool $accessible): self { $this->wheelchairAccessible = $accessible; return $this; }
    public function getAccessibility(): ?string { return $this->accessibility; }
    public function setAccessibility(?string $accessibility): self { $this->accessibility = $accessibility ?: null; return $this; }
    public function getParking(): ?string { return $this->parking; }
    public function setParking(?string $parking): self { $this->parking = $parking ?: null; return $this; }
    /** @return list<string> */
    public function getLanguages(): array { return $this->languages; }
    public function setLanguages(?array $languages): self { $this->languages = array_values(array_filter((array) $languages)); return $this; }
    public function isMain(): bool { return $this->main; }
    public function setMain(bool $main): self { $this->main = $main; return $this; }
    public function getPosition(): int { return $this->position; }
    public function setPosition(?int $position): self { $this->position = (int) $position; return $this; }

    /** "12 rue des Tilleuls, 67000 Strasbourg" */
    public function getAddress(): string
    {
        return trim(implode(', ', array_filter([$this->street, trim(($this->postalCode ?? '').' '.($this->city ?? ''))])));
    }

    /** schema.org PostalAddress. */
    public function toSchema(): array
    {
        return array_filter([
            '@type' => 'PostalAddress',
            'streetAddress' => $this->street,
            'postalCode' => $this->postalCode,
            'addressLocality' => $this->city,
            'addressCountry' => $this->country,
        ]);
    }

    /** An OpenStreetMap link to the place: no tracker, no key. */
    public function getMapUrl(): ?string
    {
        if (null !== $this->latitude && null !== $this->longitude) {
            return sprintf('https://www.openstreetmap.org/?mlat=%1$F&mlon=%2$F#map=18/%1$F/%2$F', $this->latitude, $this->longitude);
        }

        return '' !== $this->getAddress() ? 'https://www.openstreetmap.org/search?query='.rawurlencode($this->getAddress()) : null;
    }
}
