<?php

namespace Base\Office\Database;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\ConversionException;
use Doctrine\DBAL\Types\DateTimeImmutableType;

/**
 * A moment, stored in UTC and read back as UTC (`utc_datetime_immutable`).
 *
 * Doctrine's own datetime_immutable writes the wall-clock time of whatever
 * timezone the object carries and reads it back in PHP's default one - and
 * omnibase sets PHP's timezone per visitor. An appointment at 9:00 in
 * Paris would come back as 9:00 somewhere else. omnibase's own `datetime`
 * is UTC for the same reason; this is its immutable twin, for the office's
 * appointments, absences, rooms and logs.
 */
final class UtcDateTimeImmutableType extends DateTimeImmutableType
{
    public const NAME = 'utc_datetime_immutable';

    private static ?\DateTimeZone $utc = null;

    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?string
    {
        if ($value instanceof \DateTimeImmutable) {
            $value = $value->setTimezone(self::utc());
        } elseif ($value instanceof \DateTimeInterface) {
            $value = \DateTimeImmutable::createFromInterface($value)->setTimezone(self::utc());
        }

        return parent::convertToDatabaseValue($value, $platform);
    }

    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?\DateTimeImmutable
    {
        if (null === $value || $value instanceof \DateTimeImmutable) {
            return $value;
        }
        $converted = \DateTimeImmutable::createFromFormat($platform->getDateTimeFormatString(), (string) $value, self::utc())
            ?: date_create_immutable((string) $value, self::utc());
        if (!$converted) {
            throw ConversionException::conversionFailedFormat((string) $value, self::NAME, $platform->getDateTimeFormatString());
        }

        return $converted;
    }

    private static function utc(): \DateTimeZone
    {
        return self::$utc ??= new \DateTimeZone('UTC');
    }
}
