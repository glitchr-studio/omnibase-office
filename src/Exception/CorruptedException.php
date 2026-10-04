<?php

namespace Base\Office\Exception;

/** An encrypted file or text that does not decrypt: altered, truncated, or another key's. */
class CorruptedException extends \RuntimeException
{
}
