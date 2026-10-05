<?php

namespace Base\Office\Visio;

use Omnimeet\Direct\TurnCredentials as DirectTurnCredentials;

/**
 * The relay's temporary credentials, under their former name: the code is
 * omnimeet/direct's (Omnimeet\Direct\TurnCredentials), this service the
 * relay of the practice's direct gateway (Gateways::turn()).
 *
 * @deprecated since omnibase/office 1.1: use Omnimeet\Direct\TurnCredentials, or Base\Office\Visio\Gateways::turn(); removed in 2.0
 */
class TurnCredentials extends DirectTurnCredentials
{
}
