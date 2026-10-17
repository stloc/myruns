<?php

declare(strict_types=1);

namespace App\Import\Fit;

/**
 * Décodeur minimal du format binaire FIT (Garmin, Coros, Suunto…), limité aux messages utiles :
 * session (sport), lap (tours) et record (points de mesure).
 *
 * Référence : FIT SDK, « Flexible and Interoperable Data Transfer Protocol ».
 */
final class FitDecoder
{
    private const MESSAGE_SESSION = 18;
    private const MESSAGE_LAP = 19;
    private const MESSAGE_RECORD = 20;
    /** Secondes entre l'époque Unix et l'époque FIT (31/12/1989 00:00 UTC). */
    private const FIT_EPOCH_OFFSET = 631065600;

    /** Taille en octets et valeur invalide de chaque type de base (numéro = octet & 0x1F). */
    private const BASE_TYPES = [
        0 => [1, 0xFF], 1 => [1, 0x7F], 2 => [1, 0xFF], 3 => [2, 0x7FFF], 4 => [2, 0xFFFF],
        5 => [4, 0x7FFFFFFF], 6 => [4, 0xFFFFFFFF], 7 => [1, null], 8 => [4, null], 9 => [8, null],
        10 => [1, 0], 11 => [2, 0], 12 => [4, 0], 13 => [1, 0xFF], 14 => [8, null], 15 => [8, null], 16 => [8, null],
    ];

    public static function isFit(string $data): bool
    {
        return \strlen($data) >= 12 && '.FIT' === substr($data, 8, 4);
    }

    public function decode(string $data): FitActivity
    {
        if (!self::isFit($data)) {
            throw new \InvalidArgumentException('Fichier FIT invalide.');
        }

        $headerSize = \ord($data[0]);
        $dataSize = unpack('V', $data, 4)[1] ?? 0;
        $end = min(\strlen($data), $headerSize + (\is_int($dataSize) ? $dataSize : 0));
        $offset = $headerSize;

        /** @var array<int, array{global: int, littleEndian: bool, fields: list<array{0: int, 1: int, 2: int}>, devSize: int}> $definitions */
        $definitions = [];
        $lastTimestamp = 0;
        $result = new FitActivity();

        while ($offset < $end) {
            $header = \ord($data[$offset++]);

            if ($header & 0x80) {
                // En-tête à horodatage compressé : message de données, décalage sur 5 bits.
                $local = ($header >> 5) & 0x03;
                $timeOffset = $header & 0x1F;
                $timestamp = ($lastTimestamp & ~0x1F) + $timeOffset;
                if ($timeOffset < ($lastTimestamp & 0x1F)) {
                    $timestamp += 0x20;
                }
                $lastTimestamp = $timestamp;
                $offset = $this->readData($data, $offset, $definitions[$local] ?? throw new \RuntimeException('Message FIT sans définition.'), $result, $lastTimestamp, $timestamp);
                continue;
            }

            $local = $header & 0x0F;
            if ($header & 0x40) {
                // Message de définition.
                $littleEndian = 0 === \ord($data[$offset + 1]);
                $global = unpack($littleEndian ? 'v' : 'n', $data, $offset + 2)[1] ?? 0;
                $fieldCount = \ord($data[$offset + 4]);
                $offset += 5;
                $fields = [];
                for ($i = 0; $i < $fieldCount; ++$i) {
                    $fields[] = [\ord($data[$offset]), \ord($data[$offset + 1]), \ord($data[$offset + 2]) & 0x1F];
                    $offset += 3;
                }
                $devSize = 0;
                if ($header & 0x20) {
                    $devCount = \ord($data[$offset++]);
                    for ($i = 0; $i < $devCount; ++$i) {
                        $devSize += \ord($data[$offset + 1]);
                        $offset += 3;
                    }
                }
                $definitions[$local] = ['global' => \is_int($global) ? $global : 0, 'littleEndian' => $littleEndian, 'fields' => $fields, 'devSize' => $devSize];
                continue;
            }

            $offset = $this->readData($data, $offset, $definitions[$local] ?? throw new \RuntimeException('Message FIT sans définition.'), $result, $lastTimestamp, null);
        }

        return $result;
    }

    /**
     * @param array{global: int, littleEndian: bool, fields: list<array{0: int, 1: int, 2: int}>, devSize: int} $definition
     */
    private function readData(string $data, int $offset, array $definition, FitActivity $result, int &$lastTimestamp, ?int $compressedTimestamp): int
    {
        $global = $definition['global'];
        $wanted = \in_array($global, [self::MESSAGE_SESSION, self::MESSAGE_LAP, self::MESSAGE_RECORD], true);
        $values = [];

        foreach ($definition['fields'] as [$number, $size, $baseType]) {
            if ($wanted) {
                $value = $this->readValue($data, $offset, $size, $baseType, $definition['littleEndian']);
                if (null !== $value) {
                    $values[$number] = $value;
                }
            }
            $offset += $size;
        }
        $offset += $definition['devSize'];

        if (isset($values[253]) && \is_int($values[253])) {
            $lastTimestamp = $values[253];
        }
        $timestamp = $values[253] ?? $compressedTimestamp;

        match ($global) {
            self::MESSAGE_RECORD => $this->addRecord($result, $values, \is_int($timestamp) ? $timestamp : null),
            self::MESSAGE_LAP => $this->addLap($result, $values),
            self::MESSAGE_SESSION => $result->sport ??= isset($values[5]) && \is_int($values[5]) ? $values[5] : null,
            default => null,
        };

        return $offset;
    }

    private function readValue(string $data, int $offset, int $size, int $baseType, bool $littleEndian): int|float|null
    {
        [$typeSize, $invalid] = self::BASE_TYPES[$baseType] ?? [1, null];
        // Champs tableau ou chaînes : seule la première valeur numérique nous intéresse.
        if ($size < $typeSize || 7 === $baseType || 13 === $baseType) {
            return null;
        }

        $format = match ($baseType) {
            0, 2, 10 => 'C',
            1 => 'c',
            3 => $littleEndian ? 'v' : 'n',
            4, 11 => $littleEndian ? 'v' : 'n',
            5 => $littleEndian ? 'V' : 'N',
            6, 12 => $littleEndian ? 'V' : 'N',
            8 => $littleEndian ? 'g' : 'G',
            9 => $littleEndian ? 'e' : 'E',
            default => $littleEndian ? 'P' : 'J',
        };
        $value = unpack($format, $data, $offset)[1] ?? null;
        if (!\is_int($value) && !\is_float($value)) {
            return null;
        }
        if (null !== $invalid && $value === $invalid) {
            return null;
        }

        // Entiers signés lus en non signé par unpack().
        return match ($baseType) {
            3 => $value >= 0x8000 ? $value - 0x10000 : $value,
            5 => $value >= 0x80000000 ? $value - 0x100000000 : $value,
            default => $value,
        };
    }

    /**
     * @param array<int, int|float> $values
     */
    private function addRecord(FitActivity $result, array $values, ?int $timestamp): void
    {
        if (null === $timestamp) {
            return;
        }

        $altitude = $values[78] ?? $values[2] ?? null;
        $result->records[] = new FitRecord(
            timestamp: $timestamp + self::FIT_EPOCH_OFFSET,
            distanceMeters: isset($values[5]) ? $values[5] / 100 : null,
            heartRate: isset($values[3]) ? (int) $values[3] : null,
            altitudeMeters: null !== $altitude ? $altitude / 5 - 500 : null,
            latitude: isset($values[0]) ? $values[0] * (180 / 2 ** 31) : null,
            longitude: isset($values[1]) ? $values[1] * (180 / 2 ** 31) : null,
        );
    }

    /**
     * @param array<int, int|float> $values
     */
    private function addLap(FitActivity $result, array $values): void
    {
        $start = $values[2] ?? null;
        $timer = $values[8] ?? $values[7] ?? null;
        if (null === $start || null === $timer) {
            return;
        }

        $result->laps[] = new FitLap(
            startTimestamp: (int) $start + self::FIT_EPOCH_OFFSET,
            timerSeconds: $timer / 1000,
            distanceMeters: isset($values[9]) ? $values[9] / 100 : 0.0,
            averageHeartRate: isset($values[15]) ? (int) $values[15] : null,
            maxHeartRate: isset($values[16]) ? (int) $values[16] : null,
            ascentMeters: isset($values[21]) ? (int) $values[21] : null,
            intensity: isset($values[23]) ? (int) $values[23] : null,
        );
    }
}
