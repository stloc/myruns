<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\TrainingWeekOverrideRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Ajustement d'une semaine du programme (sorties en plus ou en moins, séances changées de jour ou réécrites).
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
    public private(set) ?int $sessions = null;

    /**
     * Jour prévu (1 = lundi … 7) → jour choisi, seulement pour les jours déplacés ; null : aucun.
     *
     * @var array<int, int>|null
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    public private(set) ?array $days = null;

    /**
     * Séances réécrites, par jour prévu (suivent la séance si elle change de jour) ; null : aucune.
     *
     * @var array<int, array{type: string, description: string, km: float}>|null
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    public private(set) ?array $edits = null;

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

    public function adjust(?int $sessions): void
    {
        $this->sessions = $sessions;
    }

    /**
     * Échange deux jours tels qu'affichés : la séance de l'un passe à l'autre (jour de repos compris).
     */
    public function swapDays(int $first, int $second): void
    {
        $days = [];
        for ($day = 1; $day <= 7; ++$day) {
            $shown = $this->days[$day] ?? $day;
            $shown = match ($shown) {
                $first => $second,
                $second => $first,
                default => $shown,
            };
            if ($shown !== $day) {
                $days[$day] = $shown;
            }
        }
        $this->days = [] === $days ? null : $days;
    }

    /**
     * Réécrit la séance du jour affiché.
     */
    public function editSession(int $shownDay, string $type, string $description, float $km): void
    {
        $edits = $this->edits ?? [];
        $edits[$this->plannedDay($shownDay)] = ['type' => $type, 'description' => $description, 'km' => $km];
        ksort($edits);
        $this->edits = $edits;
    }

    /**
     * Rend au jour affiché la séance du programme.
     */
    public function forgetEdit(int $shownDay): void
    {
        $edits = $this->edits ?? [];
        unset($edits[$this->plannedDay($shownDay)]);
        $this->edits = [] === $edits ? null : $edits;
    }

    public function reset(): void
    {
        $this->sessions = null;
        $this->days = null;
        $this->edits = null;
    }

    public function isEmpty(): bool
    {
        return null === $this->sessions && null === $this->days && null === $this->edits;
    }

    /** Jour prévu de la séance affichée au jour donné (les échanges forment une permutation). */
    private function plannedDay(int $shownDay): int
    {
        $planned = array_search($shownDay, $this->days ?? [], true);

        return false === $planned ? $shownDay : $planned;
    }
}
