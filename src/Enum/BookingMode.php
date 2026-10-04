<?php

namespace Base\Office\Enum;

enum BookingMode: string
{
    /** The client picks a free slot. */
    case SLOTS = 'slots';
    /** The client asks; the office fixes the time (a notary, a lawyer, home care). */
    case REQUEST = 'request';

    public function label(): string
    {
        return 'mode.'.$this->value;
    }
}
