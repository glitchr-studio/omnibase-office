<?php

namespace Base\Office\Enum;

/** How an appointment takes place. */
enum Channel: string
{
    case IN_PERSON = 'in_person';
    case VIDEO = 'video';
    case HOME = 'home';
    case PHONE = 'phone';

    public function label(): string
    {
        return 'channel.'.$this->value;
    }
}
