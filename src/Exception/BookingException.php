<?php

namespace Base\Office\Exception;

/**
 * A booking refused, its reason a translation key of the office domain
 * (booking.error.<key>): slot_taken, too_late, not_bookable, outside_area...
 */
class BookingException extends \RuntimeException
{
    public function __construct(string $key, private readonly array $parameters = [])
    {
        parent::__construct($key);
    }

    public function getKey(): string
    {
        return $this->getMessage();
    }

    public function getParameters(): array
    {
        return $this->parameters;
    }
}
