<?php

namespace Base\Office\Enum;

/** Who may book an appointment type online. */
enum BookableBy: string
{
    /** Anyone with an account. */
    case EVERYONE = 'everyone';
    /** Clients the office already knows: an appointment with this member before (BookingPolicyInterface may say more). */
    case KNOWN = 'known';
    /** Only the office books it (by phone, at the desk). */
    case STAFF = 'staff';

    public function label(): string
    {
        return 'bookable_by.'.$this->value;
    }
}
