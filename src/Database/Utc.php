<?php

namespace Base\Office\Database;

/** A moment as a query parameter: in UTC, as the columns hold it (UtcDateTimeImmutableType). */
final class Utc
{
    public static function of(\DateTimeInterface $moment): \DateTimeImmutable
    {
        return \DateTimeImmutable::createFromInterface($moment)->setTimezone(new \DateTimeZone('UTC'));
    }

    public static function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }
}
