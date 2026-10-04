<?php

namespace Base\Office\Enum;

enum AppointmentStatus: string
{
    case REQUESTED = 'requested';
    case CONFIRMED = 'confirmed';
    case CANCELLED = 'cancelled';
    case NO_SHOW = 'no_show';
    case DONE = 'done';

    /** Holds its slot: nobody else may book it. */
    public function isActive(): bool
    {
        return self::REQUESTED === $this || self::CONFIRMED === $this;
    }

    public function label(): string
    {
        return 'status.'.$this->value;
    }
}
