<?php

namespace Base\Office\Form\Model;

use Base\Office\Entity\Booking\AppointmentType;
use Base\Office\Entity\Member;
use Symfony\Component\Validator\Constraints as Assert;

/** What someone who has no account yet sends to ask for a first appointment. */
class AppointmentRequest
{
    #[Assert\NotNull]
    public ?AppointmentType $type = null;

    public ?Member $member = null;

    #[Assert\NotBlank]
    #[Assert\Length(max: 120)]
    public ?string $name = null;

    #[Assert\NotBlank]
    #[Assert\Email]
    #[Assert\Length(max: 180)]
    public ?string $email = null;

    #[Assert\NotBlank]
    #[Assert\Length(max: 30)]
    public ?string $phone = null;

    #[Assert\Length(max: 1000)]
    public ?string $message = null;

    /** Left empty by people; filled by the robots that fill every field. */
    public ?string $website = null;

    public function isRobot(): bool
    {
        return null !== $this->website && '' !== trim($this->website);
    }
}
