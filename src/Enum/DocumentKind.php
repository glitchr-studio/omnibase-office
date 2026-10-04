<?php

namespace Base\Office\Enum;

enum DocumentKind: string
{
    case RESULT = 'result';
    case PRESCRIPTION = 'prescription';
    case REPORT = 'report';
    case LETTER = 'letter';
    /** Deposited by the client: a prescription to bring, an insurance card. */
    case UPLOAD = 'upload';
    case OTHER = 'other';

    public function label(): string
    {
        return 'kind.'.$this->value;
    }
}
