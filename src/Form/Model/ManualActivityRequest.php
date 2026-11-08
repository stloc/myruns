<?php

declare(strict_types=1);

namespace App\Form\Model;

use App\Entity\Activity;
use App\Enum\Sport;
use App\Repository\ActivityRepository;
use App\Util\Units;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/** Saisie d'une activité à la main (sans trace : ni carte, ni temps de passage). */
final class ManualActivityRequest
{
    public Sport $sport = Sport::Run;

    #[Assert\NotNull(message: 'Indiquez la date et l\'heure de départ.')]
    #[Assert\LessThanOrEqual('now', message: 'L\'activité doit déjà avoir eu lieu.')]
    public ?\DateTimeImmutable $startDate = null;

    #[Assert\NotNull(message: 'Indiquez la distance.')]
    #[Assert\Range(min: 0.1, max: 1000, notInRangeMessage: 'La distance doit être comprise entre 0,1 et 1 000 km.')]
    public ?float $distanceKm = null;

    #[Assert\NotBlank(message: 'Indiquez la durée.')]
    public string $duration = '';

    #[Assert\Length(max: 255)]
    public string $name = '';

    #[Assert\PositiveOrZero]
    #[Assert\LessThanOrEqual(30000)]
    public ?int $elevationGain = null;

    #[Assert\Range(min: 30, max: 250)]
    public ?int $averageHeartRate = null;

    #[Assert\Range(min: 30, max: 250)]
    public ?int $maxHeartRate = null;

    #[Assert\Callback]
    public function validateFormats(ExecutionContextInterface $context): void
    {
        if ('' !== trim($this->duration)) {
            try {
                Units::parseDuration($this->duration);
            } catch (\InvalidArgumentException $e) {
                $context->buildViolation($e->getMessage())->atPath('duration')->addViolation();
            }
        }
        if (null !== $this->averageHeartRate && null !== $this->maxHeartRate && $this->averageHeartRate > $this->maxHeartRate) {
            $context->buildViolation('La FC moyenne ne peut pas dépasser la FC max.')->atPath('averageHeartRate')->addViolation();
        }
    }

    public function toActivity(): Activity
    {
        if (null === $this->startDate || null === $this->distanceKm) {
            throw new \LogicException('Activité incomplète : la saisie doit être validée avant.');
        }
        $seconds = Units::parseDuration($this->duration);
        $name = trim($this->name);

        return new Activity(
            id: 'manual-'.bin2hex(random_bytes(8)),
            source: ActivityRepository::SOURCE_MANUAL,
            name: '' !== $name ? $name : $this->sport->label(),
            sport: $this->sport->refinedByName($name),
            startDate: $this->startDate->setTimezone(new \DateTimeZone('UTC')),
            distanceMeters: $this->distanceKm * 1000,
            movingTimeSeconds: $seconds,
            elapsedTimeSeconds: $seconds,
            elevationGain: null === $this->elevationGain ? null : (float) $this->elevationGain,
            averageHeartRate: null === $this->averageHeartRate ? null : (float) $this->averageHeartRate,
            maxHeartRate: null === $this->maxHeartRate ? null : (float) $this->maxHeartRate,
        );
    }
}
