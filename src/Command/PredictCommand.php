<?php

declare(strict_types=1);

namespace App\Command;

use App\Ai\AiCoach;
use App\Prediction\Goal;
use App\Prediction\GoalPredictor;
use App\Prediction\PredictionFormatter;
use App\Util\Units;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:predict', description: 'Probabilité de réussir un objectif chrono à une date donnée')]
final class PredictCommand extends Command
{
    public function __construct(
        private readonly GoalPredictor $predictor,
        private readonly PredictionFormatter $formatter,
        private readonly AiCoach $coach,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('distance', InputArgument::REQUIRED, 'Distance : 5k, 10, 21.1, semi, marathon…')
            ->addArgument('time', InputArgument::REQUIRED, 'Temps visé : 45:00, 1:45:00, 3h30…')
            ->addArgument('date', InputArgument::REQUIRED, 'Date de l\'objectif : 2026-12-15 ou 15/12/2026')
            ->addOption('ai', null, InputOption::VALUE_NONE, 'Affiner avec l\'analyse du coach IA')
            ->setHelp('Exemple : <info>%command.full_name% 10k 45:00 2026-12-15 --ai</info>');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $now = new \DateTimeImmutable();
        $argument = static fn (string $name): string => \is_string($value = $input->getArgument($name)) ? $value : '';

        try {
            $goal = new Goal(
                Units::parseDistance($argument('distance')),
                Units::parseDuration($argument('time')),
                Units::parseDate($argument('date'), $now),
            );
        } catch (\InvalidArgumentException $e) {
            $io->error($e->getMessage());

            return Command::INVALID;
        }

        $prediction = $this->predictor->predict($goal, $now);
        if (null === $prediction) {
            $io->error('Pas assez de sorties de course sur les 12 dernières semaines. Lancez d\'abord « app:import ».');

            return Command::FAILURE;
        }

        $io->section('Forme');
        $io->text($this->formatter->fitnessLines($prediction->fitness));
        $io->section('Prédiction statistique');
        $io->text($this->formatter->predictionLines($prediction));

        if ($input->getOption('ai')) {
            $io->section('Coach IA');
            try {
                $io->text($this->formatter->analysisLines($this->coach->analyze($prediction)));
            } catch (\Throwable $e) {
                $io->error('Analyse IA indisponible : '.$e->getMessage());

                return Command::FAILURE;
            }
        }

        return Command::SUCCESS;
    }
}
