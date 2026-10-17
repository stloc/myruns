<?php

declare(strict_types=1);

namespace App\Entity;

use App\Prediction\Goal;
use App\Repository\AthleteProfileRepository;
use App\Util\Units;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Profil du coureur (un seul par installation). Tous les champs sont facultatifs :
 * chacun affine la prédiction quand il est renseigné.
 */
#[ORM\Entity(repositoryClass: AthleteProfileRepository::class)]
class AthleteProfile
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(nullable: true)]
    #[Assert\GreaterThanOrEqual(1920, message: 'Année de naissance invalide.')]
    public ?int $birthYear = null;

    #[ORM\Column(nullable: true)]
    #[Assert\Range(min: 120, max: 230, notInRangeMessage: 'FC max entre {{ min }} et {{ max }} bpm.')]
    public ?int $maxHeartRate = null;

    #[ORM\Column(nullable: true)]
    #[Assert\Range(min: 30, max: 100, notInRangeMessage: 'FC de repos entre {{ min }} et {{ max }} bpm.')]
    public ?int $restingHeartRate = null;

    #[ORM\Column(nullable: true)]
    #[Assert\Range(min: 1950, max: 2100, notInRangeMessage: 'Année entre {{ min }} et {{ max }}.')]
    public ?int $runningSinceYear = null;

    #[ORM\Column(nullable: true)]
    #[Assert\Range(min: 30, max: 200, notInRangeMessage: 'Poids entre {{ min }} et {{ max }} kg.')]
    public ?float $weightKg = null;

    #[ORM\Column(nullable: true)]
    #[Assert\Range(min: 30, max: 200, notInRangeMessage: 'Poids entre {{ min }} et {{ max }} kg.')]
    public ?float $targetWeightKg = null;

    /** Sorties par semaine du programme, séance club comprise (null : selon la régularité). */
    #[ORM\Column(nullable: true)]
    #[Assert\Range(min: 2, max: 6, notInRangeMessage: 'Entre {{ min }} et {{ max }} sorties par semaine.')]
    public ?int $planSessionsPerWeek = null;

    /** Jour de la séance club, 1 (lundi) à 7 (dimanche) ; null : pas de club. */
    #[ORM\Column(nullable: true)]
    #[Assert\Range(min: 1, max: 7)]
    public ?int $clubWeekday = null;

    /** Heure de la séance club (HH:MM), affichée dans le programme. */
    #[ORM\Column(length: 5, nullable: true)]
    public ?string $clubTime = null;

    /** Dernier objectif saisi sur /objectif, tel que tapé (sans effet sur les calculs). */
    #[ORM\Column(length: 32, nullable: true)]
    public private(set) ?string $lastGoalDistance = null;

    #[ORM\Column(length: 32, nullable: true)]
    public private(set) ?string $lastGoalTime = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    public private(set) ?\DateTimeImmutable $lastGoalDate = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function ageOn(\DateTimeImmutable $date): ?int
    {
        return null === $this->birthYear ? null : (int) $date->format('Y') - $this->birthYear;
    }

    public function rememberGoal(string $distance, string $time, \DateTimeImmutable $date): void
    {
        $this->lastGoalDistance = $distance;
        $this->lastGoalTime = $time;
        $this->lastGoalDate = $date;
    }

    public function lastGoal(): ?Goal
    {
        if (null === $this->lastGoalDistance || null === $this->lastGoalTime || null === $this->lastGoalDate) {
            return null;
        }

        try {
            return new Goal(Units::parseDistance($this->lastGoalDistance), Units::parseDuration($this->lastGoalTime), $this->lastGoalDate);
        } catch (\InvalidArgumentException) {
            return null;
        }
    }

    public function hasHeartRateZones(): bool
    {
        return null !== $this->maxHeartRate;
    }

    #[Assert\Callback]
    public function validateConsistency(ExecutionContextInterface $context): void
    {
        if (null !== $this->maxHeartRate && null !== $this->restingHeartRate && $this->restingHeartRate >= $this->maxHeartRate - 40) {
            $context->buildViolation('La FC de repos doit être nettement inférieure à la FC max.')->atPath('restingHeartRate')->addViolation();
        }
        if (null !== $this->targetWeightKg && null === $this->weightKg) {
            $context->buildViolation('Indiquez aussi votre poids actuel.')->atPath('weightKg')->addViolation();
        }
        $currentYear = (int) date('Y');
        if (null !== $this->birthYear && $this->birthYear > $currentYear - 10) {
            $context->buildViolation('Année de naissance invalide.')->atPath('birthYear')->addViolation();
        }
        if (null !== $this->runningSinceYear && $this->runningSinceYear > $currentYear) {
            $context->buildViolation('Cette année est dans le futur.')->atPath('runningSinceYear')->addViolation();
        }
    }
}
