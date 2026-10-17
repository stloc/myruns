<?php

declare(strict_types=1);

namespace App\Tests\Fit;

/**
 * Fabrique des fichiers FIT synthétiques pour les tests (aucune trace réelle dans le dépôt).
 */
final class FitFileBuilder
{
    private const FIT_EPOCH_OFFSET = 631065600;

    private string $records = '';

    /** @var array<int, true> types locaux déjà définis */
    private array $defined = [];

    public function session(int $sport): self
    {
        $this->define(2, 18, [[5, 1, 0x00]]);
        $this->records .= \chr(2).\chr($sport);

        return $this;
    }

    /**
     * Point de mesure ; $compressed utilise l'en-tête à horodatage compressé (décalage ≤ 31 s).
     */
    public function record(int $unixTimestamp, float $distanceMeters, ?int $heartRate, bool $compressed = false): self
    {
        $fitTimestamp = $unixTimestamp - self::FIT_EPOCH_OFFSET;
        $hr = \chr($heartRate ?? 0xFF);
        $distance = pack('V', (int) round($distanceMeters * 100));

        if ($compressed) {
            $this->define(1, 20, [[5, 4, 0x86], [3, 1, 0x02]]);
            $this->records .= \chr(0x80 | (1 << 5) | ($fitTimestamp & 0x1F)).$distance.$hr;
        } else {
            $this->define(0, 20, [[253, 4, 0x86], [5, 4, 0x86], [3, 1, 0x02]]);
            $this->records .= \chr(0).pack('V', $fitTimestamp).$distance.$hr;
        }

        return $this;
    }

    public function lap(int $unixStart, float $seconds, float $distanceMeters, int $averageHeartRate, int $maxHeartRate): self
    {
        $this->define(3, 19, [[2, 4, 0x86], [8, 4, 0x86], [9, 4, 0x86], [15, 1, 0x02], [16, 1, 0x02]]);
        $this->records .= \chr(3).pack('VVV', $unixStart - self::FIT_EPOCH_OFFSET, (int) round($seconds * 1000), (int) round($distanceMeters * 100))
            .\chr($averageHeartRate).\chr($maxHeartRate);

        return $this;
    }

    public function build(): string
    {
        $header = \chr(14).\chr(0x20).pack('v', 2132).pack('V', \strlen($this->records)).'.FIT'.pack('v', 0);

        return $header.$this->records.pack('v', 0);
    }

    /**
     * @param list<array{0: int, 1: int, 2: int}> $fields numéro, taille, type de base
     */
    private function define(int $local, int $global, array $fields): void
    {
        if (isset($this->defined[$local])) {
            return;
        }

        $this->records .= \chr(0x40 | $local).\chr(0).\chr(0).pack('v', $global).\chr(\count($fields));
        foreach ($fields as [$number, $size, $type]) {
            $this->records .= \chr($number).\chr($size).\chr($type);
        }
        $this->defined[$local] = true;
    }
}
