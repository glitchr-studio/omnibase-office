<?php

namespace Base\Office\Event;

use App\Entity\User;
use Base\Office\Entity\Invitation;
use Symfony\Contracts\EventDispatcher\Event;

/** A client's account opened from an invitation: a regime makes its own record of them (health: the patient profile). */
final class ClientAccountEvent extends Event
{
    public const CREATED = 'office.client.created';

    public function __construct(public readonly User $user, public readonly Invitation $invitation)
    {
    }
}
