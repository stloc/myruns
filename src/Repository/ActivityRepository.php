<?php

declare(strict_types=1);

namespace App\Repository;

use App\Doctrine\UtcDateTimeImmutableType;
use App\Entity\Activity;
use App\Enum\ActivitySort;
use App\Enum\Sport;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Activity>
 */
final class ActivityRepository extends ServiceEntityRepository
{
    public const SOURCE_CSV = 'strava_csv';
    public const SOURCE_FILE = 'track_file';

    private const RUNNING_SPORTS = [Sport::Run, Sport::TrailRun, Sport::VirtualRun];

    /** @var array<string, Activity>|null activités connues par identifiant, chargées pour un import */
    private ?array $byId = null;

    /** @var array<string, array<string, Activity>> mêmes activités par minute de départ (plusieurs possibles) */
    private array $byStartKey = [];

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Activity::class);
    }

    /**
     * @return list<Activity> du plus récent au plus ancien
     */
    public function findLatest(int $limit, int $offset = 0): array
    {
        return $this->search(new ActivitySearch(), $limit, $offset);
    }

    /**
     * @return list<Activity> activités correspondant aux critères, dans l'ordre demandé
     */
    public function search(ActivitySearch $search, int $limit, int $offset = 0): array
    {
        $qb = $this->searchQuery($search)
            ->setFirstResult($offset)
            ->setMaxResults($limit);

        match ($search->sort) {
            ActivitySort::Date => $qb->orderBy('a.startDate', \SortDirection::Descending),
            ActivitySort::Distance => $qb->orderBy('a.distanceMeters', \SortDirection::Descending),
            ActivitySort::Duration => $qb->orderBy('a.movingTimeSeconds', \SortDirection::Descending),
            ActivitySort::Elevation => $qb->addSelect('COALESCE(a.groundElevationGain, a.elevationGain, 0) AS HIDDEN elevation')
                ->orderBy('elevation', \SortDirection::Descending),
            // Les activités sans distance (renforcement, piscine sans longueurs) passent en dernier.
            ActivitySort::Pace => $qb->addSelect('CASE WHEN a.distanceMeters > 0 THEN a.movingTimeSeconds / a.distanceMeters ELSE 1000000 END AS HIDDEN pace')
                ->orderBy('pace', \SortDirection::Ascending),
        };
        if (ActivitySort::Date !== $search->sort) {
            $qb->addOrderBy('a.startDate', \SortDirection::Descending);
        }

        /* @var list<Activity> */
        return $qb->getQuery()->getResult();
    }

    public function countMatching(ActivitySearch $search): int
    {
        $count = $this->searchQuery($search)->select('COUNT(a.id)')->getQuery()->getSingleScalarResult();

        return is_numeric($count) ? (int) $count : 0;
    }

    private function searchQuery(ActivitySearch $search): QueryBuilder
    {
        $qb = $this->createQueryBuilder('a');

        foreach ($search->words as $i => $word) {
            // LIKE ignore la casse (lettres sans accent) ; les jokers saisis sont pris littéralement.
            $qb->andWhere(\sprintf("a.name LIKE :word%d ESCAPE '!'", $i))
                ->setParameter('word'.$i, '%'.addcslashes($word, '%_!').'%');
        }
        if ($search->allRuns) {
            $qb->andWhere('a.sport IN (:sports)')->setParameter('sports', self::RUNNING_SPORTS);
        } elseif (null !== $search->sport) {
            $qb->andWhere('a.sport = :sport')->setParameter('sport', $search->sport);
        }
        if (null !== $search->from) {
            $qb->andWhere('a.startDate >= :from')->setParameter('from', $search->from, UtcDateTimeImmutableType::NAME);
        }
        if (null !== $search->to) {
            $qb->andWhere('a.startDate < :to')->setParameter('to', $search->to, UtcDateTimeImmutableType::NAME);
        }
        if (null !== $search->minKm) {
            $qb->andWhere('a.distanceMeters >= :minDistance')->setParameter('minDistance', $search->minKm * 1000);
        }
        if (null !== $search->maxKm) {
            $qb->andWhere('a.distanceMeters <= :maxDistance')->setParameter('maxDistance', $search->maxKm * 1000);
        }
        if (null !== $search->minElevation) {
            $qb->andWhere('COALESCE(a.groundElevationGain, a.elevationGain, 0) >= :minElevation')->setParameter('minElevation', $search->minElevation);
        }

        return $qb;
    }

    public function findMostRecent(): ?Activity
    {
        return $this->findLatest(1)[0] ?? null;
    }

    /**
     * @return list<Activity> courses à pied depuis la date donnée, de la plus récente à la plus ancienne
     */
    public function findRunsSince(\DateTimeImmutable $since): array
    {
        /* @var list<Activity> */
        return $this->createQueryBuilder('a')
            ->andWhere('a.sport IN (:sports)')
            ->andWhere('a.startDate >= :since')
            ->setParameter('sports', self::RUNNING_SPORTS)
            ->setParameter('since', $since, UtcDateTimeImmutableType::NAME)
            ->orderBy('a.startDate', \SortDirection::Descending)
            ->getQuery()
            ->getResult();
    }

    /**
     * Date de la plus ancienne course à pied connue (début de l'historique de course).
     */
    public function firstRunDate(): ?\DateTimeImmutable
    {
        $first = $this->createQueryBuilder('a')
            ->select('MIN(a.startDate)')
            ->andWhere('a.sport IN (:sports)')
            ->setParameter('sports', self::RUNNING_SPORTS)
            ->getQuery()
            ->getSingleScalarResult();

        return \is_string($first) ? new \DateTimeImmutable($first, new \DateTimeZone('UTC')) : null;
    }

    /**
     * Kilomètres de course par semaine (lundi), y compris les semaines sans sortie.
     *
     * @return array<string, float> date du lundi (Y-m-d) → km, de la plus ancienne à la plus récente
     */
    public function weeklyRunningKm(int $weeks, \DateTimeImmutable $now = new \DateTimeImmutable()): array
    {
        $firstMonday = $now->modify('monday this week')->setTime(0, 0)->modify(\sprintf('-%d weeks', $weeks - 1));

        // Regroupement par lundi dans le fuseau de $now (portable, sans fonction de date SQL).
        $km = [];
        foreach ($this->findRunsSince($firstMonday) as $run) {
            $week = $run->startDate->setTimezone($now->getTimezone())->modify('monday this week')->format('Y-m-d');
            $km[$week] = ($km[$week] ?? 0.0) + $run->distanceMeters / 1000;
        }

        $result = [];
        for ($i = 0; $i < $weeks; ++$i) {
            $week = $firstMonday->modify(\sprintf('+%d weeks', $i))->format('Y-m-d');
            $result[$week] = round($km[$week] ?? 0.0, 1);
        }

        return $result;
    }

    /**
     * Ajoute ou met à jour une activité.
     *
     * 1. Même identifiant : mise à jour de l'activité connue.
     * 2. Sinon, même sortie venant d'une autre source (départ à ±1 minute) : une ligne du CSV
     *    (plus complète) remplace une trace ; une trace ne remplace jamais une ligne du CSV.
     *    Deux lignes du CSV ne sont jamais fusionnées : leurs identifiants Strava sont uniques.
     */
    public function upsert(Activity $activity): UpsertResult
    {
        $known = $this->knownActivities();
        $existing = $known[$activity->id] ?? $this->findSameSessionFromOtherSource($activity);

        if (null === $existing) {
            $this->getEntityManager()->persist($activity);
            $this->remember($activity);

            return new UpsertResult(UpsertOutcome::Created, $activity);
        }

        if (self::SOURCE_CSV === $existing->source && self::SOURCE_CSV !== $activity->source) {
            return new UpsertResult(UpsertOutcome::Unchanged, $existing);
        }
        if ($existing->toArray() === $activity->toArray()) {
            return new UpsertResult(UpsertOutcome::Unchanged, $existing);
        }

        $this->forget($existing);
        if ($existing->id === $activity->id) {
            $existing->replaceWith($activity);
            $this->remember($existing);

            return new UpsertResult(UpsertOutcome::Updated, $existing);
        }

        // Trace remplacée par la ligne du CSV de la même sortie : on remplace l'entité.
        $this->getEntityManager()->remove($existing);
        $this->getEntityManager()->persist($activity);
        $this->remember($activity);

        return new UpsertResult(UpsertOutcome::Updated, $activity);
    }

    /**
     * Activités rattachées à un fichier de trace de l'export, indexées par nom de fichier sans « .gz ».
     *
     * @return array<string, Activity>
     */
    public function indexByTrackFile(): array
    {
        $index = [];
        foreach ($this->knownActivities() as $activity) {
            if (null !== $activity->trackFile) {
                $index[self::trackKey($activity->trackFile)] = $activity;
            }
        }

        return $index;
    }

    public static function trackKey(string $filename): string
    {
        return strtolower(basename(preg_replace('/\.gz$/i', '', $filename) ?? $filename));
    }

    /**
     * FC la plus haute tenue 10 s sur les courses depuis la date donnée (calculée sur les traces).
     */
    public function findHighestSustainedHeartRateSince(\DateTimeImmutable $since): ?Activity
    {
        $activity = $this->createQueryBuilder('a')
            ->andWhere('a.sport IN (:sports)')
            ->andWhere('a.startDate >= :since')
            ->andWhere('a.sustainedMaxHeartRate IS NOT NULL')
            ->setParameter('sports', self::RUNNING_SPORTS)
            ->setParameter('since', $since, UtcDateTimeImmutableType::NAME)
            ->orderBy('a.sustainedMaxHeartRate', \SortDirection::Descending)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $activity instanceof Activity ? $activity : null;
    }

    public function flush(): void
    {
        $this->getEntityManager()->flush();
        $this->byId = null;
        $this->byStartKey = [];
    }

    /**
     * Même sortie enregistrée par une autre source (ou par deux traces) : départ à ±1 minute.
     */
    private function findSameSessionFromOtherSource(Activity $activity): ?Activity
    {
        $start = $activity->startDate->setTimezone(new \DateTimeZone('UTC'));
        foreach (['+0 minute', '-1 minute', '+1 minute'] as $shift) {
            foreach ($this->byStartKey[Activity::keyFor($start->modify($shift))] ?? [] as $candidate) {
                if (self::SOURCE_CSV !== $candidate->source || self::SOURCE_CSV !== $activity->source) {
                    return $candidate;
                }
            }
        }

        return null;
    }

    /**
     * Toutes les activités, chargées une seule fois par import : évite une requête par ligne du CSV.
     *
     * @return array<string, Activity> indexées par identifiant
     */
    private function knownActivities(): array
    {
        if (null === $this->byId) {
            $this->byId = [];
            $this->byStartKey = [];
            foreach ($this->findAll() as $activity) {
                $this->remember($activity);
            }
        }

        return $this->byId ?? [];
    }

    private function remember(Activity $activity): void
    {
        $this->byId[$activity->id] = $activity;
        $this->byStartKey[$activity->startKey][$activity->id] = $activity;
    }

    private function forget(Activity $activity): void
    {
        unset($this->byId[$activity->id], $this->byStartKey[$activity->startKey][$activity->id]);
    }
}
