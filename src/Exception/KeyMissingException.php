<?php

namespace Base\Office\Exception;

/**
 * No master key, or not a usable one: the vault fails closed - nothing is
 * stored in clear, nothing is read. Set it in the secrets vault:
 * `bin/console secrets:set SHARE_MASTER_KEY` (base64 of 32 random bytes).
 */
class KeyMissingException extends \RuntimeException
{
}
