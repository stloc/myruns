<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\ActivityRepository;
use App\Weather\OpenMeteoWeather;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:weather', description: 'Complète la météo des sorties GPS qui n\'en ont pas encore (Open-Meteo)')]
final class WeatherCommand extends Command
{
    /** Sorties enregistrées par lot : une interruption ne perd que le lot en cours. */
    private const BATCH_SIZE = 20;

    public function __construct(
        private readonly ActivityRepository $activities,
        private readonly OpenMeteoWeather $weather,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setHelp(<<<'HELP'
            Pose la météo (température, vent, pluie…) des sorties importées avant cette fonction, ou
            dont l'import n'a pas pu joindre Open-Meteo. Relançable autant de fois que voulu : seules
            les sorties sans météo sont traitées (un réimport de l'archive la complète aussi).

              <info>%command.full_name%</info>
            HELP);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $starts = $this->activities->startsWithoutWeather();
        if ([] === $starts) {
            $io->success('Toutes les sorties avec une trace GPS ont leur météo.');

            return Command::SUCCESS;
        }

        $found = 0;
        $progress = $io->createProgressBar(\count($starts));
        foreach ($starts as $i => $start) {
            $activity = $this->activities->find($start['id']);
            $weather = null === $activity ? null : $this->weather->during($start['latitude'], $start['longitude'], $activity->startDate, $activity->elapsedTimeSeconds);
            if (null !== $activity && null !== $weather) {
                $activity->replaceWeather($weather);
                ++$found;
            }
            if (0 === ($i + 1) % self::BATCH_SIZE) {
                $this->activities->flush();
            }
            $progress->advance();
        }
        $this->activities->flush();
        $progress->finish();
        $io->newLine(2);

        $missing = \count($starts) - $found;
        if ($missing > 0) {
            $io->warning(\sprintf('Météo indisponible pour %d sortie(s) (service injoignable, ou sortie de moins de quelques jours) : relancer plus tard.', $missing));
        }
        $io->success(\sprintf('Météo ajoutée à %d sortie(s).', $found));

        return Command::SUCCESS;
    }
}
