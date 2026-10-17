<?php

declare(strict_types=1);

namespace App\Util;

/**
 * Conversions saisie utilisateur ⇄ valeurs internes (mètres, secondes).
 */
final class Units
{
    private const NAMED_DISTANCES = [
        'marathon' => 42195.0,
        'semi' => 21097.5,
        'semi-marathon' => 21097.5,
        'half' => 21097.5,
    ];

    /**
     * Accepte « 10 », « 10k », « 10 km », « 21.1 », « 5000m », « semi », « marathon ».
     */
    public static function parseDistance(string $input): float
    {
        $value = mb_strtolower(str_replace(',', '.', trim($input)));
        if (isset(self::NAMED_DISTANCES[$value])) {
            return self::NAMED_DISTANCES[$value];
        }

        if (!preg_match('/^(\d+(?:\.\d+)?)\s*(km|k|m)?$/', $value, $m)) {
            throw new \InvalidArgumentException(\sprintf('Distance « %s » non reconnue (ex. 10, 10k, 21.1, semi, marathon).', $input));
        }

        $number = (float) $m[1];
        $meters = 'm' === ($m[2] ?? '') ? $number : $number * 1000;
        if ($meters < 1000 || $meters > 100000) {
            throw new \InvalidArgumentException('La distance doit être comprise entre 1 et 100 km.');
        }

        return $meters;
    }

    /**
     * Accepte « 45:00 », « 1:45:00 », « 3h30 », « 3h30m », « 45min », « 50 ».
     */
    public static function parseDuration(string $input): int
    {
        $value = mb_strtolower(str_replace(' ', '', trim($input)));

        if (preg_match('/^(\d+):(\d{1,2}):(\d{1,2})$/', $value, $m)) {
            $seconds = (int) $m[1] * 3600 + (int) $m[2] * 60 + (int) $m[3];
        } elseif (preg_match('/^(\d+):(\d{1,2})$/', $value, $m)) {
            $seconds = (int) $m[1] * 60 + (int) $m[2];
        } elseif (preg_match('/^(\d+)h(\d{1,2})?(?:m(?:in)?)?$/', $value, $m)) {
            $seconds = (int) $m[1] * 3600 + (int) ($m[2] ?? 0) * 60;
        } elseif (preg_match('/^(\d+)(?:m|min|mn)?$/', $value, $m)) {
            $seconds = (int) $m[1] * 60;
        } else {
            throw new \InvalidArgumentException(\sprintf('Temps « %s » non reconnu (ex. 45:00, 1:45:00, 3h30).', $input));
        }

        if ($seconds <= 0) {
            throw new \InvalidArgumentException('Le temps doit être positif.');
        }

        return $seconds;
    }

    /**
     * Accepte « 2026-12-15 » ou « 15/12/2026 ».
     */
    public static function parseDate(string $input, \DateTimeImmutable $today): \DateTimeImmutable
    {
        $value = trim($input);
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value)
            ?: \DateTimeImmutable::createFromFormat('!d/m/Y', $value);

        if (false === $date) {
            throw new \InvalidArgumentException(\sprintf('Date « %s » non reconnue (ex. 2026-12-15 ou 15/12/2026).', $input));
        }
        if ($date < $today->setTime(0, 0)) {
            throw new \InvalidArgumentException('La date de l\'objectif doit être dans le futur.');
        }

        return $date;
    }

    public static function formatDuration(int|float $seconds): string
    {
        $seconds = (int) round($seconds);
        $h = intdiv($seconds, 3600);
        $m = intdiv($seconds % 3600, 60);
        $s = $seconds % 60;

        return $h > 0 ? \sprintf('%d:%02d:%02d', $h, $m, $s) : \sprintf('%d:%02d', $m, $s);
    }

    /** Écart de temps signé : « +1:05 », « −0:12 », « = » si identique. */
    public static function formatGap(int|float $seconds): string
    {
        $seconds = (int) round($seconds);

        return match (true) {
            0 === $seconds => '=',
            $seconds > 0 => '+'.self::formatDuration($seconds),
            default => "\u{2212}".self::formatDuration(-$seconds),
        };
    }

    public static function formatPace(?float $secondsPerKm): string
    {
        if (null === $secondsPerKm || $secondsPerKm <= 0) {
            return '–';
        }

        return \sprintf('%s/km', self::formatDuration($secondsPerKm));
    }

    public static function formatDistance(float $meters): string
    {
        return match ($meters) {
            42195.0 => 'Marathon',
            21097.5 => 'Semi-marathon',
            default => rtrim(rtrim(number_format($meters / 1000, 2, ',', ''), '0'), ',').' km',
        };
    }

    /**
     * Nombre décimal écrit à l'anglaise (« 1234.5 ») ou à la française (« 1 234,5 »).
     */
    public static function parseNumber(string $value): ?float
    {
        $value = str_replace(["\u{a0}", "\u{202f}", ' '], '', trim($value));
        if ('' === $value) {
            return null;
        }
        if (str_contains($value, ',') && !str_contains($value, '.')) {
            $value = str_replace(',', '.', $value);
        } else {
            $value = str_replace(',', '', $value);
        }

        return is_numeric($value) ? (float) $value : null;
    }
}
