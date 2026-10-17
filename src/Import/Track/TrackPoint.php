<?php

declare(strict_types=1);

namespace App\Import\Track;

/**
 * Point de mesure d'une trace, quel que soit son format (FIT, GPX, TCX).
 */
final readonly class TrackPoint
{
    public function __construct(
        public int $timestamp,
        /** Distance cumulée depuis le départ (m). */
        public float $distanceMeters,
        public ?int $heartRate = null,
        public ?float $altitudeMeters = null,
        public ?float $latitude = null,
        public ?float $longitude = null,
    ) {
    }

    public function hasPosition(): bool
    {
        return null !== $this->latitude && null !== $this->longitude;
    }

    public function withAltitude(float $altitudeMeters): self
    {
        return new self($this->timestamp, $this->distanceMeters, $this->heartRate, $altitudeMeters, $this->latitude, $this->longitude);
    }
}
