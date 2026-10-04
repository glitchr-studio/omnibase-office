<?php

namespace Base\Office\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * A client invited to open their account (omnibase's invitation by token):
 * the secretary took an appointment on the phone for someone new - nobody
 * signs up by themselves into an office's client space.
 */
#[ORM\Entity]
#[ORM\Table(name: 'office_invitation')]
class Invitation extends \Base\Entity\User\Invitation
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(length: 30, nullable: true)]
    private ?string $phone = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPhone(): ?string
    {
        return $this->phone;
    }

    public function setPhone(?string $phone): static
    {
        $this->phone = $phone ?: null;

        return $this;
    }
}
