<?php

declare(strict_types=1);

namespace App\Prediction;

use App\Entity\AthleteProfile;

/**
 * Réserve cardiaque (Karvonen) : part de l'écart entre FC de repos et FC max utilisée par un effort.
 */
final readonly class HeartRateReserve
{
    /**
     * FC de repos supposée quand elle n'est pas renseignée. La formule « % FC max → % VO2max »
     * (Swain) surestime fortement la forme aux allures modérées : Karvonen avec une FC de repos
     * moyenne reste bien plus proche des chronos réels.
     */
    public const DEFAULT_RESTING_HEART_RATE = 60;

    public function __construct(
        public int $max,
        public int $resting = self::DEFAULT_RESTING_HEART_RATE,
    ) {
    }

    /**
     * Null sans FC max dans le profil.
     */
    public static function of(AthleteProfile $profile): ?self
    {
        return null === $profile->maxHeartRate ? null : new self($profile->maxHeartRate, $profile->restingHeartRate ?? self::DEFAULT_RESTING_HEART_RATE);
    }

    /**
     * Fraction de la réserve utilisée (0 au repos, 1 à la FC max).
     */
    public function fraction(float $heartRate): float
    {
        return ($heartRate - $this->resting) / ($this->max - $this->resting);
    }
}
