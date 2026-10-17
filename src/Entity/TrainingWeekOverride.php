<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\TrainingWeekOverrideRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Ajustement d'une semaine du programme (club manqué, sorties en plus ou en moins).
 */
#[ORM\Entity(repositoryClass: TrainingWeekOverrideRepository::class)]
#[ORM\UniqueConstraint(columns: ['week_start'])]
class TrainingWeekOverride
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** null : réglage général. */
    #[ORM\Column(nullable: true)]
    public private(set) ?bool $club = null;

    #[ORM\Column(nullable: true)]
    public private(set) ?int $sessions = null;

    public function __construct(
        /** Lundi de la semaine concernée. */
        #[ORM\Column(type: Types::DATE_IMMUTABLE)]
        public private(set) \DateTimeImmutable $weekStart,
    ) {
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function adjust(?bool $club, ?int $sessions): void
    {
        $this->club = $club;
        $this->sessions = $sessions;
    }

    public function isEmpty(): bool
    {
        return null === $this->club && null === $this->sessions;
    }
}
