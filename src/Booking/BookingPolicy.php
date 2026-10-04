<?php

namespace Base\Office\Booking;

use Base\Office\Entity\Booking\AppointmentType;
use Base\Office\Entity\Member;
use Base\Office\Enum\BookableBy;
use Base\Office\Repository\Booking\AppointmentRepository;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Whether a client may book this type with this member online: open to
 * everyone, to the clients the member has already seen, or to the staff
 * alone (then by phone).
 */
class BookingPolicy
{
    public function __construct(private readonly AppointmentRepository $appointments)
    {
    }

    public function canBook(AppointmentType $type, Member $member, ?UserInterface $client): bool
    {
        if (!$type->isActive() || !$member->isBookable() || !$type->isOfferedBy($member)) {
            return false;
        }

        return match ($type->getBookableBy()) {
            BookableBy::EVERYONE => true,
            BookableBy::KNOWN => null !== $client && $this->appointments->hasSeen($client, $member),
            BookableBy::STAFF => false,
        };
    }

    /** Whether it is shown at all before signing in (a KNOWN type is: the sign-in will tell). */
    public function isListed(AppointmentType $type, Member $member): bool
    {
        return $type->isActive() && $member->isBookable() && $type->isOfferedBy($member) && BookableBy::STAFF !== $type->getBookableBy();
    }
}
