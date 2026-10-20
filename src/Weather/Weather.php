<?php

declare(strict_types=1);

namespace App\Weather;

/**
 * Météo pendant une activité (Open-Meteo) : valeurs de l'heure la plus proche du milieu de la
 * sortie, précipitations cumulées sur les heures qu'elle couvre.
 */
final readonly class Weather
{
    /** Directions du vent, par huitième de tour depuis le nord. */
    private const WIND_DIRECTIONS = ['N', 'NE', 'E', 'SE', 'S', 'SO', 'O', 'NO'];

    /**
     * @param float    $temperature   °C, à 2 m
     * @param int      $code          code météo de l'OMM (0 = ciel clair … 99 = orage avec grêle)
     * @param int|null $humidity      humidité relative, %
     * @param float    $windSpeed     km/h, à 10 m
     * @param int|null $windDirection degrés, d'où vient le vent (0 = nord)
     * @param float    $precipitation mm tombés pendant la sortie
     */
    public function __construct(
        public float $temperature,
        public int $code,
        public ?float $apparentTemperature = null,
        public ?int $humidity = null,
        public ?float $dewPoint = null,
        public float $windSpeed = 0.0,
        public ?float $windGusts = null,
        public ?int $windDirection = null,
        public float $precipitation = 0.0,
    ) {
    }

    public function conditionLabel(): string
    {
        return match (true) {
            0 === $this->code => 'Ciel clair',
            1 === $this->code => 'Plutôt dégagé',
            2 === $this->code => 'Partiellement nuageux',
            3 === $this->code => 'Couvert',
            \in_array($this->code, [45, 48], true) => 'Brouillard',
            $this->code >= 51 && $this->code <= 57 => 'Bruine',
            $this->code >= 61 && $this->code <= 67 => 'Pluie',
            $this->code >= 71 && $this->code <= 77 => 'Neige',
            $this->code >= 80 && $this->code <= 82 => 'Averses',
            \in_array($this->code, [85, 86], true) => 'Averses de neige',
            $this->code >= 95 => 'Orage',
            default => 'Inconnu',
        };
    }

    /**
     * Nom de l'icône (templates/_icon.html.twig) du temps observé.
     */
    public function conditionIcon(): string
    {
        return match (true) {
            0 === $this->code => 'sun',
            \in_array($this->code, [1, 2], true) => 'cloud-sun',
            \in_array($this->code, [45, 48], true) => 'fog',
            $this->code >= 51 && $this->code <= 57 => 'drizzle',
            ($this->code >= 61 && $this->code <= 67) || ($this->code >= 80 && $this->code <= 82) => 'rain',
            ($this->code >= 71 && $this->code <= 77) || \in_array($this->code, [85, 86], true) => 'snow',
            $this->code >= 95 => 'storm',
            default => 'cloud',
        };
    }

    public function windDirectionLabel(): ?string
    {
        return null === $this->windDirection ? null : self::WIND_DIRECTIONS[(int) round($this->windDirection / 45) % 8];
    }

    /**
     * @return array{temperature: float, code: int, apparent_temperature: float|null, humidity: int|null, dew_point: float|null, wind_speed: float, wind_gusts: float|null, wind_direction: int|null, precipitation: float}
     */
    public function toArray(): array
    {
        return [
            'temperature' => $this->temperature,
            'code' => $this->code,
            'apparent_temperature' => $this->apparentTemperature,
            'humidity' => $this->humidity,
            'dew_point' => $this->dewPoint,
            'wind_speed' => $this->windSpeed,
            'wind_gusts' => $this->windGusts,
            'wind_direction' => $this->windDirection,
            'precipitation' => $this->precipitation,
        ];
    }

    /**
     * @param array<mixed> $data
     */
    public static function fromArray(array $data): ?self
    {
        $float = static fn (string $key): ?float => is_numeric($data[$key] ?? null) ? (float) $data[$key] : null;
        $int = static fn (string $key): ?int => is_numeric($data[$key] ?? null) ? (int) round((float) $data[$key]) : null;

        $temperature = $float('temperature');
        $code = $int('code');
        if (null === $temperature || null === $code) {
            return null;
        }

        return new self(
            temperature: $temperature,
            code: $code,
            apparentTemperature: $float('apparent_temperature'),
            humidity: $int('humidity'),
            dewPoint: $float('dew_point'),
            windSpeed: $float('wind_speed') ?? 0.0,
            windGusts: $float('wind_gusts'),
            windDirection: $int('wind_direction'),
            precipitation: $float('precipitation') ?? 0.0,
        );
    }
}
