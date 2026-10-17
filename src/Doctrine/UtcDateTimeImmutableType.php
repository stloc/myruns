<?php

declare(strict_types=1);

namespace App\Doctrine;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\DateTimeImmutableType;
use Doctrine\DBAL\Types\Exception\InvalidFormat;

/**
 * Date et heure stockées en UTC, relues en UTC.
 *
 * SQLite ne conserve pas le décalage horaire : sans conversion, une date saisie à Paris serait
 * relue dans le fuseau par défaut de PHP, qui varie d'une machine à l'autre.
 */
final class UtcDateTimeImmutableType extends DateTimeImmutableType
{
    public const string NAME = 'utc_datetime_immutable';

    private static ?\DateTimeZone $utc = null;

    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?string
    {
        if ($value instanceof \DateTimeImmutable) {
            $value = $value->setTimezone(self::utc());
        }

        return parent::convertToDatabaseValue($value, $platform);
    }

    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?\DateTimeImmutable
    {
        if (null === $value || $value instanceof \DateTimeImmutable) {
            return $value;
        }
        if (!\is_string($value)) {
            throw InvalidFormat::new(get_debug_type($value), self::class, $platform->getDateTimeFormatString());
        }

        $date = \DateTimeImmutable::createFromFormat('!'.$platform->getDateTimeFormatString(), $value, self::utc());
        if (false === $date) {
            throw InvalidFormat::new($value, self::class, $platform->getDateTimeFormatString());
        }

        return $date;
    }

    private static function utc(): \DateTimeZone
    {
        return self::$utc ??= new \DateTimeZone('UTC');
    }
}
