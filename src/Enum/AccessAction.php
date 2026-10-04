<?php

namespace Base\Office\Enum;

enum AccessAction: string
{
    case VIEW = 'view';
    case DOWNLOAD = 'download';
    case UPLOAD = 'upload';
    case REVOKE = 'revoke';
    case SHARE = 'share';
    /** Someone asked and was refused. */
    case DENIED = 'denied';

    public function label(): string
    {
        return 'access.'.$this->value;
    }
}
