<?php

namespace Base\Office\Entity;

use Base\Office\Repository\ContactRequestRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * A message from the contact form, kept for the back office as long as the
 * form's notice says (office.contact.retention_months), then purged.
 */
#[ORM\Entity(repositoryClass: ContactRequestRepository::class)]
#[ORM\Table(name: 'office_contact_request')]
#[ORM\Index(columns: ['createdAt'], name: 'office_contact_created_idx')]
class ContactRequest
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    protected $id;

    #[ORM\Column(length: 120)]
    protected string $name = '';

    #[ORM\Column(length: 180)]
    protected string $email = '';

    #[ORM\Column(length: 30, nullable: true)]
    protected ?string $phone = null;

    #[ORM\Column(type: 'text')]
    protected string $message = '';

    #[ORM\Column(type: 'utc_datetime_immutable')]
    protected \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'utc_datetime_immutable', nullable: true)]
    protected ?\DateTimeImmutable $handledAt = null;

    public function __construct(string $name = '', string $email = '', string $message = '', ?string $phone = null)
    {
        $this->name = $name;
        $this->email = $email;
        $this->message = $message;
        $this->phone = $phone ?: null;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function __toString(): string
    {
        return $this->name;
    }

    public function getId(): ?int { return $this->id; }
    public function getName(): string { return $this->name; }
    public function getEmail(): string { return $this->email; }
    public function getPhone(): ?string { return $this->phone; }
    public function getMessage(): string { return $this->message; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getHandledAt(): ?\DateTimeImmutable { return $this->handledAt; }
    public function isHandled(): bool { return null !== $this->handledAt; }
    public function setHandled(bool $handled): self { $this->handledAt = $handled ? ($this->handledAt ?? new \DateTimeImmutable()) : null; return $this; }
}
