<?php

declare(strict_types=1);

namespace App\Repository;

use App\Enum\ActivitySort;
use App\Enum\Sport;
use App\Util\Units;

/**
 * Critères de recherche d'activités, lus depuis l'adresse de la page (formulaire GET).
 * Une valeur vide ou invalide est ignorée : la recherche ne renvoie jamais d'erreur.
 */
final readonly class ActivitySearch
{
    /** Valeur du filtre de type regroupant route, trail et course virtuelle. */
    public const ALL_RUNS = 'course';

    public \SortDirection $direction;

    /**
     * @param list<string> $words mots à trouver tous dans le nom
     */
    public function __construct(
        public array $words = [],
        public ?Sport $sport = null,
        public bool $allRuns = false,
        public ?\DateTimeImmutable $from = null,
        public ?\DateTimeImmutable $to = null,
        public ?float $minKm = null,
        public ?float $maxKm = null,
        public ?float $minElevation = null,
        public ActivitySort $sort = ActivitySort::Date,
        ?\SortDirection $direction = null,
    ) {
        $this->direction = $direction ?? $sort->defaultDirection();
    }

    /**
     * @param array<mixed> $query paramètres de l'adresse ($request->query->all())
     */
    public static function fromQuery(array $query, \DateTimeZone $timezone = new \DateTimeZone('Europe/Paris')): self
    {
        $get = static fn (string $key): string => \is_string($query[$key] ?? null) ? $query[$key] : '';
        $text = trim($get('q'));
        $sport = $get('sport');

        return new self(
            words: '' === $text ? [] : array_values(array_unique(preg_split('/\s+/u', $text) ?: [])),
            sport: Sport::tryFrom($sport),
            allRuns: self::ALL_RUNS === $sport,
            from: self::date($get('du'), $timezone),
            to: self::date($get('au'), $timezone)?->modify('+1 day'),
            minKm: self::positive($get('km_min')),
            maxKm: self::positive($get('km_max')),
            minElevation: self::positive($get('dplus_min')),
            sort: ActivitySort::tryFrom($get('tri')) ?? ActivitySort::Date,
            direction: match ($get('ordre')) {
                'asc' => \SortDirection::Ascending,
                'desc' => \SortDirection::Descending,
                default => null,
            },
        );
    }

    public function isFiltered(): bool
    {
        return [] !== $this->words || null !== $this->sport || $this->allRuns || null !== $this->from || null !== $this->to
            || null !== $this->minKm || null !== $this->maxKm || null !== $this->minElevation;
    }

    /**
     * Paramètres d'adresse reproduisant la recherche (liens de pagination).
     *
     * @return array<string, string>
     */
    public function toQuery(): array
    {
        return array_filter([
            'q' => implode(' ', $this->words),
            'sport' => $this->allRuns ? self::ALL_RUNS : ($this->sport->value ?? ''),
            'du' => $this->from?->format('Y-m-d') ?? '',
            'au' => $this->to?->modify('-1 day')->format('Y-m-d') ?? '',
            'km_min' => self::number($this->minKm),
            'km_max' => self::number($this->maxKm),
            'dplus_min' => self::number($this->minElevation),
            'tri' => ActivitySort::Date === $this->sort ? '' : $this->sort->value,
            'ordre' => $this->sort->defaultDirection() === $this->direction ? '' : self::directionValue($this->direction),
        ], static fn (string $value): bool => '' !== $value);
    }

    private static function directionValue(\SortDirection $direction): string
    {
        return \SortDirection::Ascending === $direction ? 'asc' : 'desc';
    }

    private static function date(string $value, \DateTimeZone $timezone): ?\DateTimeImmutable
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, $timezone);

        return false !== $date && $date->format('Y-m-d') === $value ? $date : null;
    }

    private static function positive(string $value): ?float
    {
        $number = Units::parseNumber($value);

        return null !== $number && $number >= 0 ? $number : null;
    }

    private static function number(?float $value): string
    {
        return null === $value ? '' : rtrim(rtrim(\sprintf('%.2F', $value), '0'), '.');
    }
}
