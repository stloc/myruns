<?php

declare(strict_types=1);

namespace App\Import;

use App\Entity\Activity;
use App\Enum\Sport;
use App\Repository\ActivityRepository;
use App\Util\Units;

/**
 * Lit le fichier activities.csv de l'export de compte Strava (en-têtes anglais ou français).
 *
 * Le CSV contient certaines colonnes en double (« Elapsed Time », « Distance ») : la première
 * occurrence est en unités d'affichage (km), la dernière en unités SI (m, s). On garde la dernière.
 */
final class StravaCsvParser
{
    private const COLUMNS = [
        'id' => ['activity id', 'id de l activite'],
        'date' => ['activity date', 'date de l activite'],
        'name' => ['activity name', 'nom de l activite'],
        'type' => ['activity type', 'type d activite'],
        'elapsed' => ['elapsed time', 'temps ecoule', 'duree totale'],
        'moving' => ['moving time', 'duree de deplacement', 'temps de deplacement'],
        'distance' => ['distance'],
        'elevation' => ['elevation gain', 'denivele positif'],
        'heart_rate' => ['average heart rate', 'frequence cardiaque moyenne'],
        'max_heart_rate' => ['max heart rate', 'frequence cardiaque max', 'frequence cardiaque maximale'],
        'filename' => ['filename', 'nom du fichier'],
    ];

    private const FRENCH_MONTHS = [
        'janv' => 'Jan', 'févr' => 'Feb', 'fevr' => 'Feb', 'mars' => 'Mar', 'avr' => 'Apr', 'mai' => 'May',
        'juin' => 'Jun', 'juil' => 'Jul', 'août' => 'Aug', 'aout' => 'Aug', 'sept' => 'Sep', 'oct' => 'Oct',
        'nov' => 'Nov', 'déc' => 'Dec', 'dec' => 'Dec',
    ];

    /**
     * @param resource $stream
     *
     * @return iterable<Activity>
     */
    public function parse($stream): iterable
    {
        $header = fgetcsv($stream, escape: '');
        if (!\is_array($header)) {
            return;
        }

        $columns = $this->mapColumns($header);
        foreach (['id', 'date', 'type', 'distance'] as $required) {
            if (!isset($columns[$required])) {
                throw new \RuntimeException(\sprintf('Colonne « %s » introuvable dans activities.csv : export Strava non reconnu.', $required));
            }
        }
        $distanceIsKm = 1 === $columns['distance_count'];

        while (\is_array($row = fgetcsv($stream, escape: ''))) {
            $value = static fn (string $key): string => isset($columns[$key]) ? trim((string) ($row[$columns[$key]] ?? '')) : '';

            $date = $this->parseDate($value('date'));
            $distance = Units::parseNumber($value('distance')) ?? 0.0;
            if ($distanceIsKm) {
                $distance *= 1000;
            }
            if (null === $date || '' === $value('id')) {
                continue;
            }

            $elapsed = (int) (Units::parseNumber($value('elapsed')) ?? 0);
            $moving = (int) (Units::parseNumber($value('moving')) ?? 0);
            // Strava écrit 0 quand l'activité n'a pas de mesure cardiaque.
            $heartRate = Units::parseNumber($value('heart_rate'));
            $maxHeartRate = Units::parseNumber($value('max_heart_rate'));

            yield new Activity(
                id: $value('id'),
                source: ActivityRepository::SOURCE_CSV,
                name: $value('name'),
                sport: Sport::fromLabel($value('type')),
                startDate: $date,
                distanceMeters: $distance,
                movingTimeSeconds: $moving > 0 ? $moving : $elapsed,
                elapsedTimeSeconds: $elapsed > 0 ? $elapsed : $moving,
                elevationGain: Units::parseNumber($value('elevation')),
                averageHeartRate: $heartRate > 0 ? $heartRate : null,
                maxHeartRate: $maxHeartRate > 0 ? $maxHeartRate : null,
                trackFile: '' !== $value('filename') ? $value('filename') : null,
            );
        }
    }

    /**
     * @param array<int, string|null> $header
     *
     * @return array<string, int> nom logique → index de colonne (dernière occurrence), plus « distance_count »
     */
    private function mapColumns(array $header): array
    {
        $columns = ['distance_count' => 0];
        foreach ($header as $index => $label) {
            $normalized = $this->normalize((string) $label);
            foreach (self::COLUMNS as $key => $aliases) {
                if (\in_array($normalized, $aliases, true)) {
                    $columns[$key] = $index;
                    if ('distance' === $key) {
                        ++$columns['distance_count'];
                    }
                }
            }
        }

        return $columns;
    }

    private function normalize(string $label): string
    {
        $label = preg_replace('/^\x{FEFF}/u', '', $label) ?? $label;
        $label = mb_strtolower(trim($label));
        $label = strtr($label, ['à' => 'a', 'â' => 'a', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'î' => 'i', 'ô' => 'o', 'û' => 'u', 'ç' => 'c']);
        $label = preg_replace('/[^a-z0-9]+/', ' ', $label) ?? $label;

        return trim($label);
    }

    /**
     * Formats rencontrés : « Jan 5, 2024, 7:12:34 AM » (EN) et « 5 janv. 2024, 07:12:34 » (FR), en UTC.
     */
    private function parseDate(string $value): ?\DateTimeImmutable
    {
        if ('' === $value) {
            return null;
        }

        $utc = new \DateTimeZone('UTC');
        $value = str_replace('.', '', mb_strtolower($value));
        $value = preg_replace_callback(
            '/\b('.implode('|', array_map(preg_quote(...), array_keys(self::FRENCH_MONTHS))).')\b/u',
            static fn (array $m): string => self::FRENCH_MONTHS[$m[1]],
            $value,
        ) ?? $value;

        foreach (['M j, Y, g:i:s A', 'j M Y, H:i:s', 'j M Y H:i:s', 'Y-m-d H:i:s'] as $format) {
            $date = \DateTimeImmutable::createFromFormat($format, ucwords($value), $utc);
            if (false !== $date) {
                return $date;
            }
        }

        try {
            return new \DateTimeImmutable($value, $utc);
        } catch (\Exception) {
            return null;
        }
    }
}
