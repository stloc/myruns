<?php

declare(strict_types=1);

namespace App\Training;

use App\Prediction\FitnessSnapshot;
use App\Prediction\Goal;
use App\Prediction\Vdot;
use App\Util\Units;

/**
 * Programme d'entraînement déterministe, de la semaine en cours jusqu'à la semaine de l'objectif :
 * foncier → développement → spécifique → affûtage, une semaine allégée toutes les quatre,
 * volume +10 %/semaine au plus à partir du kilométrage actuel.
 */
final class TrainingPlanBuilder
{
    public const MIN_SESSIONS = 2;
    public const MAX_SESSIONS = 6;
    public const WEEKDAYS = [1 => 'Lundi', 2 => 'Mardi', 3 => 'Mercredi', 4 => 'Jeudi', 5 => 'Vendredi', 6 => 'Samedi', 7 => 'Dimanche'];

    private const WEEKLY_INCREASE = 1.10;
    private const RECOVERY_EVERY = 4;
    private const RECOVERY_RATIO = 0.75;
    /** Volume de la semaine d'affûtage par rapport au pic, en partant de la semaine de l'objectif. */
    private const TAPER_RATIOS = [0.45, 0.65, 0.8];
    private const KM_PER_SESSION_FLOOR = 4.0;
    private const MIN_EASY_KM = 4.0;
    /** Échauffement (2 km) et retour au calme (2 km) des séances de qualité. */
    private const WARMUP_KM = 4.0;
    /** Séance club : ≈ 20 min d'endurance fondamentale, puis ≈ 5 km de fractionné, récupérations et retour au calme. */
    private const CLUB_WARMUP_SECONDS = 1200;
    private const CLUB_WORK_KM = 5.0;
    private const CLUB_INTENSITY_RATIO = 0.8;
    private const LONG_RUN_STEP_KM = 1.5;
    private const LONG_RUN_SHARE = 0.5;
    private const LONG_RUN_TOLERANCE = 0.8;
    /** Part maximale du volume hebdomadaire courue à chaque intensité (règles de Daniels, élargies). */
    private const THRESHOLD_SHARE = 0.15;
    private const INTERVAL_SHARE = 0.12;
    private const GOAL_PACE_SHARE = 0.25;
    private const MARATHON_PACE_SHARE = 0.30;
    /** Faute d'historique, la forme est supposée un peu en dessous de celle que demande l'objectif. */
    private const VDOT_MARGIN_WITHOUT_HISTORY = 3.0;
    /** Ordre de préférence des jours, à écart égal avec les séances déjà placées. */
    private const QUALITY_DAYS = [2, 4, 5, 3, 6, 1];
    private const EASY_DAYS = [1, 5, 2, 4, 6, 3];

    public function defaultSessionsPerWeek(FitnessSnapshot $fitness): int
    {
        return max(self::MIN_SESSIONS, min(5, (int) round($fitness->runsPerWeek())));
    }

    public function build(Goal $goal, FitnessSnapshot $fitness, PlanOptions $options = new PlanOptions(), \DateTimeImmutable $now = new \DateTimeImmutable()): ?TrainingPlan
    {
        $today = $now->setTime(0, 0);
        $goalDay = $goal->date->setTime(0, 0);
        if ($goalDay <= $today) {
            return null;
        }

        $sessions = self::clampSessions($options->sessionsPerWeek ?? $this->defaultSessionsPerWeek($fitness));
        $clubDay = null !== $options->clubWeekday && isset(self::WEEKDAYS[$options->clubWeekday]) ? $options->clubWeekday : null;
        $distanceKm = $goal->distanceMeters / 1000;
        $notes = [];

        $vdot = $fitness->vdot;
        if (null === $vdot) {
            $vdot = Vdot::fromPerformance($goal->distanceMeters, $goal->targetSeconds) - self::VDOT_MARGIN_WITHOUT_HISTORY;
            $notes[] = 'Pas assez de sorties récentes pour mesurer votre forme : allures estimées à partir de l\'objectif, à revoir après quelques semaines d\'import.';
        } else {
            $notes[] = \sprintf('Allures calculées sur votre forme actuelle (indice %s) : le programme se recalcule à chaque import.', number_format($vdot, 1, ',', ''));
        }
        $paces = TrainingPaces::from($vdot, $goal);

        $firstMonday = $today->modify('monday this week');
        $count = intdiv((int) $firstMonday->diff($goalDay->modify('monday this week'))->days, 7) + 1;
        $phases = $this->phases($count, $distanceKm);
        $buildCount = \count(array_filter($phases, static fn (Phase $phase): bool => Phase::Taper !== $phase));

        $startKm = max($fitness->kmPerWeekLast4Weeks, self::KM_PER_SESSION_FLOOR * $sessions);
        $targetKm = max($startKm, min(14.0 * $sessions, max(20 + $distanceKm, 9.0 * $sessions)));
        $longTarget = min(32.0, 8 + 0.55 * $distanceKm);
        $level = $startKm;
        $longLevel = max(6.0, min($fitness->longestRunKmLast8Weeks, $longTarget));

        $weeks = [];
        $progress = [];
        for ($i = 0; $i < $count; ++$i) {
            $phase = $phases[$i];
            $monday = $firstMonday->modify(\sprintf('+%d weeks', $i));
            $adjustment = $options->adjustmentFor($monday);
            $weekSessions = self::clampSessions($adjustment->sessions ?? $sessions);
            $club = null !== $clubDay && ($adjustment->club ?? true);
            $adjusted = $weekSessions !== $sessions || (null !== $clubDay && !$club);
            // Moins de sorties que prévu : le volume baisse d'autant ; davantage : il se répartit.
            $share = min(1.0, $weekSessions / $sessions);

            if (Phase::Taper === $phase) {
                $ratio = self::TAPER_RATIOS[$count - 1 - $i] ?? self::TAPER_RATIOS[2];
                if ($i === $count - 1) {
                    $raceDay = (int) $goalDay->format('N');
                    $club = $club && $clubDay <= $raceDay - 2;
                    $list = $this->raceWeek($goal, $paces, $weekSessions, $raceDay, $club ? $clubDay : null, $options->clubTime);
                } else {
                    $list = $this->week($phase, false, 0, $weekSessions, $share * $level * $ratio, min($longLevel * ($ratio + 0.1), $longTarget), $paces, $distanceKm, $club ? $clubDay : null, $options->clubTime);
                }
                $weeks[] = new TrainingWeek($i + 1, $monday, $phase, false, $list, $club, $adjusted, $weekSessions);
                continue;
            }

            $recovery = $buildCount >= 5 && 0 === ($i + 1) % self::RECOVERY_EVERY && $i < $buildCount - 1;
            if (!$recovery && $i > 0) {
                $level = min($targetKm, $level * self::WEEKLY_INCREASE);
                $longLevel = min($longTarget, $longLevel + self::LONG_RUN_STEP_KM);
            }
            $volume = $share * ($recovery ? $level * self::RECOVERY_RATIO : $level);
            $long = $recovery ? $longLevel * self::RECOVERY_RATIO : $longLevel;
            $step = $progress[$phase->value] ?? 0;
            if (!$recovery) {
                $progress[$phase->value] = $step + 1;
            }

            $list = $this->week($phase, $recovery, $step, $weekSessions, $volume, $long, $paces, $distanceKm, $club ? $clubDay : null, $options->clubTime);
            $weeks[] = new TrainingWeek($i + 1, $monday, $phase, $recovery, $list, $club, $adjusted, $weekSessions);
        }

        if ($count >= 4 && $level < $targetKm - 1) {
            $notes[] = \sprintf('Volume conseillé ≈ %d km/semaine non atteint d\'ici l\'objectif : la progression est limitée à +10 %% par semaine pour éviter la blessure.', (int) round($targetKm));
        }
        if ($sessions < 3) {
            $notes[] = 'Avec 2 sorties par semaine, la progression reste limitée : ajoutez un footing dès que possible.';
        } elseif ($distanceKm > 30 && $sessions < 4) {
            $notes[] = 'Pour un marathon, 4 séances par semaine ou plus sont conseillées.';
        }
        if (null !== $clubDay) {
            $notes[] = \sprintf('La séance club du %s compte comme une sortie et remplace une séance de qualité. Une semaine sans club : décochez-la dans la semaine concernée.', mb_strtolower(self::WEEKDAYS[$clubDay]));
        }
        if ($count < 4) {
            $notes[] = 'Moins de 4 semaines avant l\'objectif : peu de progression possible, le programme vise surtout à arriver frais.';
        }

        return new TrainingPlan($goal, $paces, $sessions, $weeks, $notes, $clubDay, $options->clubTime);
    }

    public static function clampSessions(int $sessions): int
    {
        return max(self::MIN_SESSIONS, min(self::MAX_SESSIONS, $sessions));
    }

    /**
     * @return list<Phase>
     */
    private function phases(int $count, float $distanceKm): array
    {
        $taper = min($count, match (true) {
            $distanceKm <= 10.5 => 1,
            $distanceKm <= 25 => 2,
            default => 3,
        });
        $build = $count - $taper;
        $specific = $build >= 3 ? min(6, max(1, (int) round($build * 0.3))) : $build;
        $development = min(8, (int) ceil(($build - $specific) / 2));
        $base = $build - $specific - $development;

        return [
            ...array_fill(0, $base, Phase::Base),
            ...array_fill(0, $development, Phase::Development),
            ...array_fill(0, $specific, Phase::Specific),
            ...array_fill(0, $taper, Phase::Taper),
        ];
    }

    /**
     * @return list<TrainingSession>
     */
    private function week(Phase $phase, bool $recovery, int $step, int $sessions, float $volume, float $long, TrainingPaces $paces, float $distanceKm, ?int $clubDay, ?string $clubTime): array
    {
        // Le fractionné du club entame la part d'intensité de la semaine.
        $quality = $this->qualitySessions($phase, $recovery, $step, $sessions, null === $clubDay ? $volume : self::CLUB_INTENSITY_RATIO * $volume, $paces, $distanceKm);
        if (null !== $clubDay) {
            // Le club fait du fractionné ou des côtes : il prend la place de la VMA, sinon de la dernière séance
            // de qualité ; jamais celle du travail à l'allure objectif, qu'il remplace alors un footing.
            $slot = Phase::Development === $phase ? 0 : \count($quality) - 1;
            if (SessionType::GoalPace === $quality[$slot]['type'] && Phase::Specific === $phase && $sessions - 1 > \count($quality)) {
                $quality[] = $this->club($phase, $recovery, $paces, $clubTime);
            } else {
                $quality[$slot] = $this->club($phase, $recovery, $paces, $clubTime);
            }
        }

        $wantedLong = min($long, self::LONG_RUN_SHARE * $volume);
        while (true) {
            $easyCount = $sessions - 1 - \count($quality);
            $fixedKm = array_sum(array_map(static fn (array $q): float => $q['km'] ?? 0.0, $quality));
            $sizedLikeEasy = $easyCount + \count(array_filter($quality, static fn (array $q): bool => null === $q['km']));
            $room = $volume - $fixedKm - self::MIN_EASY_KM * $sizedLikeEasy;
            // Semaine trop chargée : une 2ᵉ séance de qualité (hors club et allure objectif) devient un footing avant de raccourcir la sortie longue.
            $removable = array_keys(array_filter($quality, static fn (array $q): bool => !\in_array($q['type'], [SessionType::Club, SessionType::GoalPace], true) && null !== $q['km']));
            if ($room >= self::LONG_RUN_TOLERANCE * $wantedLong || \count($quality) < 2 || [] === $removable) {
                break;
            }
            unset($quality[$removable[\count($removable) - 1]]);
            $quality = array_values($quality);
        }
        // Semaine juste : la sortie longue ne perd pas plus de 20 %, quitte à dépasser un peu le volume prévu.
        $long = self::round(max(6.0, min($wantedLong, max($room, self::LONG_RUN_TOLERANCE * $wantedLong))));
        $easyKm = self::round(max(self::MIN_EASY_KM, ($volume - $long - $fixedKm) / max(1, $sizedLikeEasy)));

        $easy = new TrainingSession(SessionType::Easy, \sprintf('Footing en aisance respiratoire à %s.', $this->easyRange($paces)), $easyKm);
        $planned = array_map(static fn (array $q): TrainingSession => new TrainingSession($q['type'], $q['text'], $q['km'] ?? $easyKm), $quality);
        $longRun = new TrainingSession(SessionType::Long, \sprintf('Sortie longue à %s, régulière, sans chercher la vitesse.', Units::formatPace($paces->easySlow)), $long);

        return $this->schedule($planned, array_fill(0, max(0, $easyCount), $easy), $longRun, $clubDay);
    }

    /**
     * @return list<array{type: SessionType, text: string, km: ?float}>
     */
    private function qualitySessions(Phase $phase, bool $recovery, int $step, int $sessions, float $volume, TrainingPaces $paces, float $distanceKm): array
    {
        $strides = ['type' => SessionType::Strides, 'text' => \sprintf('Footing à %s puis 6 × 20 s d\'accélération progressive (récup. en marchant).', $this->easyRange($paces)), 'km' => null];
        if ($recovery) {
            return [$strides];
        }

        $twoSessions = $sessions >= 4;

        return match ($phase) {
            Phase::Base => $twoSessions ? [$strides, $this->hills($step)] : [$strides],
            Phase::Development => $twoSessions
                ? [$this->interval($step, $volume, $paces), $this->threshold($step, $volume, $paces)]
                : [0 === $step % 2 ? $this->threshold(intdiv($step, 2), $volume, $paces) : $this->interval(intdiv($step, 2), $volume, $paces)],
            Phase::Specific => $twoSessions
                ? [$this->goalPace($step, $volume, $paces, $distanceKm), $distanceKm <= 7 ? $this->interval($step + 1, $volume, $paces) : $this->threshold($step + 2, $volume, $paces)]
                : [0 === $step % 2 ? $this->goalPace(intdiv($step, 2), $volume, $paces, $distanceKm) : $this->threshold($step + 1, $volume, $paces)],
            Phase::Taper => $twoSessions ? [$this->taperReminder($paces, $distanceKm), $strides] : [$this->taperReminder($paces, $distanceKm)],
        };
    }

    /**
     * @return array{type: SessionType, text: string, km: float}
     */
    private function club(Phase $phase, bool $recovery, TrainingPaces $paces, ?string $time, bool $raceWeek = false): array
    {
        $advice = match (true) {
            $raceWeek => 'semaine de l\'objectif : faire l\'échauffement et quelques accélérations, pas de fractionné',
            $recovery => 'semaine allégée : faire l\'échauffement et la moitié des répétitions seulement',
            Phase::Taper === $phase => 'affûtage : moitié des répétitions, sans finir à bloc',
            Phase::Base === $phase => 'idéal en côtes ou en fractions courtes, sans finir à bloc',
            Phase::Specific === $phase => \sprintf('si le choix est libre, viser l\'allure objectif %s sur les fractions longues', Units::formatPace($paces->goal)),
            default => \sprintf('fractions de 2 à 4 min vers %s, ou côtes', Units::formatPace($paces->interval)),
        };

        return [
            'type' => SessionType::Club,
            'text' => \sprintf('%s≈ 20 min d\'endurance fondamentale à %s puis fractionné ou côtes selon l\'entraîneur. Conseil : %s.',
                null === $time || '' === $time ? '' : 'À '.$time.' : ', $this->easyRange($paces), $advice),
            'km' => self::round(self::CLUB_WARMUP_SECONDS / $paces->easy() + ($raceWeek ? 2.0 : self::CLUB_WORK_KM)),
        ];
    }

    /**
     * @return list<TrainingSession>
     */
    private function raceWeek(Goal $goal, TrainingPaces $paces, int $sessions, int $raceDay, ?int $clubDay, ?string $clubTime): array
    {
        $thirtyMinutesKm = self::round(30 * 60 / $paces->easy());
        $placed = [$raceDay => new TrainingSession(SessionType::Race, \sprintf('Objectif : %s. Partir à %s, sans s\'emballer sur le premier kilomètre.', $goal->describe(), Units::formatPace($paces->goal)), self::round($goal->distanceMeters / 1000), $raceDay)];

        $reminderDay = $clubDay ?? $raceDay - 3;
        if ($sessions >= 2 && $reminderDay >= 1) {
            $placed[$reminderDay] = null !== $clubDay
                ? $this->toSession($this->club(Phase::Taper, false, $paces, $clubTime, true), $reminderDay)
                : new TrainingSession(SessionType::Strides, \sprintf('Footing de 30 min dont 3 × 1 min à l\'allure objectif (%s).', Units::formatPace($paces->goal)), $thirtyMinutesKm, $reminderDay);
        }
        $easy = new TrainingSession(SessionType::Easy, 'Footing court et très facile (30 min).', $thirtyMinutesKm);
        foreach ([$raceDay - 2, $raceDay - 5, $raceDay - 1, $raceDay - 4, $raceDay - 6] as $day) {
            if (\count($placed) >= min($sessions, 4)) {
                break;
            }
            if ($day >= 1 && !isset($placed[$day])) {
                $placed[$day] = $easy->on($day);
            }
        }
        ksort($placed);

        return array_values($placed);
    }

    /**
     * Répartit les séances sur la semaine : sortie longue le dimanche (samedi si le club a lieu le dimanche),
     * club à son jour, puis chaque séance de qualité et chaque footing le plus loin possible des séances déjà placées.
     *
     * @param list<TrainingSession> $quality
     * @param list<TrainingSession> $easy
     *
     * @return list<TrainingSession>
     */
    private function schedule(array $quality, array $easy, TrainingSession $long, ?int $clubDay): array
    {
        $longDay = 7 === $clubDay ? 6 : 7;
        $placed = [$longDay => $long->on($longDay)];
        $others = [];
        foreach ($quality as $session) {
            if (SessionType::Club === $session->type && null !== $clubDay) {
                $placed[$clubDay] = $session->on($clubDay);
            }
        }
        foreach ($quality as $session) {
            if (SessionType::Club === $session->type) {
                continue;
            } elseif ($session->type->isQuality() || SessionType::Hills === $session->type) {
                $day = self::farthestDay(array_keys($placed), self::QUALITY_DAYS);
                $placed[$day] = $session->on($day);
            } else {
                $others[] = $session;
            }
        }
        foreach ([...$others, ...$easy] as $session) {
            $day = self::farthestDay(array_keys($placed), self::EASY_DAYS);
            $placed[$day] = $session->on($day);
        }
        ksort($placed);

        return array_values($placed);
    }

    /**
     * @param list<int>           $taken
     * @param non-empty-list<int> $preference
     */
    private static function farthestDay(array $taken, array $preference): int
    {
        $best = $preference[0];
        $bestGap = -1;
        foreach ($preference as $day) {
            if (\in_array($day, $taken, true)) {
                continue;
            }
            // Écart circulaire : la sortie longue du dimanche précède le lundi suivant.
            $gap = min(array_map(static fn (int $other): int => min(abs($day - $other), 7 - abs($day - $other)), [] === $taken ? [7] : $taken));
            if ($gap > $bestGap) {
                [$best, $bestGap] = [$day, $gap];
            }
        }

        return $best;
    }

    /**
     * @param array{type: SessionType, text: string, km: float} $session
     */
    private function toSession(array $session, int $weekday): TrainingSession
    {
        return new TrainingSession($session['type'], $session['text'], $session['km'], $weekday);
    }

    /**
     * @return array{type: SessionType, text: string, km: float}
     */
    private function hills(int $step): array
    {
        $reps = 6 + 2 * min(2, $step);

        return [
            'type' => SessionType::Hills,
            'text' => \sprintf('Échauffement 2 km, %d × 30 s en côte à effort soutenu (retour en trottinant), retour au calme 2 km.', $reps),
            'km' => self::round(self::WARMUP_KM + $reps * 0.25),
        ];
    }

    /**
     * @return array{type: SessionType, text: string, km: float}
     */
    private function threshold(int $step, float $volume, TrainingPaces $paces): array
    {
        [$reps, $seconds] = self::pick([[3, 480], [3, 600], [2, 900], [4, 600]], $step, self::THRESHOLD_SHARE * $volume, static fn (int $r, int $sec): float => $r * $sec / $paces->threshold);

        return $this->timedReps(SessionType::Threshold, $reps, $seconds, 120, $paces->threshold, $paces);
    }

    /**
     * @return array{type: SessionType, text: string, km: float}
     */
    private function interval(int $step, float $volume, TrainingPaces $paces): array
    {
        [$reps, $seconds] = self::pick([[6, 120], [5, 180], [6, 180], [5, 240]], $step, self::INTERVAL_SHARE * $volume, static fn (int $r, int $sec): float => $r * $sec / $paces->interval);

        return $this->timedReps(SessionType::Interval, $reps, $seconds, $seconds >= 240 ? 150 : 120, $paces->interval, $paces);
    }

    /**
     * @return array{type: SessionType, text: string, km: float}
     */
    private function goalPace(int $step, float $volume, TrainingPaces $paces, float $distanceKm): array
    {
        $progression = match (true) {
            $distanceKm <= 7 => [[5, 1000], [4, 1200], [6, 1000]],
            $distanceKm <= 15 => [[4, 1500], [3, 2000], [5, 1600]],
            $distanceKm <= 30 => [[3, 3000], [2, 5000], [3, 4000]],
            default => [[2, 5000], [3, 5000], [2, 8000]],
        };
        [$reps, $meters] = self::pick($progression, $step, ($distanceKm > 30 ? self::MARATHON_PACE_SHARE : self::GOAL_PACE_SHARE) * $volume, static fn (int $r, int $m): float => $r * $m / 1000);

        return $this->distanceReps(SessionType::GoalPace, $reps, $meters, $distanceKm <= 15 ? 120 : 180, $paces);
    }

    /**
     * @return array{type: SessionType, text: string, km: float}
     */
    private function taperReminder(TrainingPaces $paces, float $distanceKm): array
    {
        [$reps, $meters] = match (true) {
            $distanceKm <= 7 => [3, 1000],
            $distanceKm <= 15 => [3, 1500],
            $distanceKm <= 30 => [3, 2000],
            default => [2, 4000],
        };

        return $this->distanceReps(SessionType::GoalPace, $reps, $meters, 120, $paces);
    }

    /**
     * @return array{type: SessionType, text: string, km: float}
     */
    private function timedReps(SessionType $type, int $reps, int $seconds, int $recoverySeconds, float $pace, TrainingPaces $paces): array
    {
        return [
            'type' => $type,
            'text' => \sprintf('Échauffement 2 km, %d × %s à %s (récup. %s de trot), retour au calme 2 km.',
                $reps, self::minutes($seconds), Units::formatPace($pace), self::minutes($recoverySeconds)),
            'km' => self::round(self::WARMUP_KM + $reps * $seconds / $pace + ($reps - 1) * $recoverySeconds / $paces->easySlow),
        ];
    }

    /**
     * @return array{type: SessionType, text: string, km: float}
     */
    private function distanceReps(SessionType $type, int $reps, int $meters, int $recoverySeconds, TrainingPaces $paces): array
    {
        return [
            'type' => $type,
            'text' => \sprintf('Échauffement 2 km, %d × %s m à l\'allure objectif %s (récup. %s de trot), retour au calme 2 km.',
                $reps, number_format($meters, 0, ',', ' '), Units::formatPace($paces->goal), self::minutes($recoverySeconds)),
            'km' => self::round(self::WARMUP_KM + $reps * $meters / 1000 + ($reps - 1) * $recoverySeconds / $paces->easySlow),
        ];
    }

    private function easyRange(TrainingPaces $paces): string
    {
        return \sprintf('%s–%s/km', Units::formatDuration($paces->easyFast), Units::formatDuration($paces->easySlow));
    }

    /**
     * Étape de progression la plus avancée (≤ $step) dont le travail tient dans $maxKm ; la première sinon,
     * allégée en répétitions si besoin.
     *
     * @param non-empty-list<array{int, int}> $progression [répétitions, durée en s ou distance en m]
     * @param \Closure(int, int): float       $workKm      distance courue à l'intensité pour une étape
     *
     * @return array{int, int}
     */
    private static function pick(array $progression, int $step, float $maxKm, \Closure $workKm): array
    {
        for ($i = min($step, \count($progression) - 1); $i > 0; --$i) {
            if ($workKm(...$progression[$i]) <= $maxKm) {
                return $progression[$i];
            }
        }

        // Petit volume : même la première étape est trop longue, on retire des répétitions (2 au minimum).
        [$reps, $unit] = $progression[0];
        $perRep = $workKm(1, $unit);

        return [max(2, min($reps, (int) floor($maxKm / $perRep))), $unit];
    }

    private static function minutes(int $seconds): string
    {
        return 0 === $seconds % 60 ? \sprintf('%d min', intdiv($seconds, 60)) : \sprintf('%d min %02d', intdiv($seconds, 60), $seconds % 60);
    }

    private static function round(float $km): float
    {
        return round($km * 2) / 2;
    }
}
