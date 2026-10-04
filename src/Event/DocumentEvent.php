<?php

namespace Base\Office\Event;

use Base\Office\Entity\Share\Document;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * A document deposited or withdrawn. office.document.deposited is what
 * mails the recipient "a document awaits" (never its title); a regime may
 * add its own notice (a message, a push).
 */
final class DocumentEvent extends Event
{
    public const DEPOSITED = 'office.document.deposited';
    public const REVOKED = 'office.document.revoked';

    public function __construct(public readonly Document $document, public readonly bool $notify = true)
    {
    }
}
