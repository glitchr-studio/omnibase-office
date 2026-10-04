<?php

namespace Base\Office\Enum;

enum AppointmentSource: string
{
    case ONLINE = 'online';
    case PHONE = 'phone';
    case STAFF = 'staff';

    public function label(): string
    {
        return 'source.'.$this->value;
    }
}
