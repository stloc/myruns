<?php

declare(strict_types=1);

namespace App\Geocoding;

/**
 * Ville du départ d'une sortie : l'IGN en France (commune exacte, même en montagne), Nominatim
 * (OpenStreetMap) ailleurs.
 */
final class CityFinder
{
    public function __construct(
        private readonly IgnReverseGeocoder $ign,
        private readonly NominatimReverseGeocoder $worldwide,
    ) {
    }

    /**
     * @return string|null null si aucun service ne connaît de ville à cet endroit (en mer) ou ne répond
     */
    public function cityAt(float $latitude, float $longitude): ?string
    {
        return $this->ign->cityAt($latitude, $longitude) ?? $this->worldwide->cityAt($latitude, $longitude);
    }
}
