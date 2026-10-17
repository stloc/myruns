<?php

declare(strict_types=1);

namespace App\Command;

use App\Sync\IntervalsSynchronizer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpExceptionInterface;

#[AsCommand(name: 'app:sync', description: 'Récupère les nouvelles séances depuis intervals.icu (montre → intervals.icu → MyRuns)')]
final class SyncCommand extends Command
{
    public function __construct(private readonly IntervalsSynchronizer $synchronizer)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('since', null, InputOption::VALUE_REQUIRED, 'Date de début (AAAA-MM-JJ) ; par défaut, 3 jours avant la dernière séance connue')
            ->setHelp(<<<'HELP'
                Télécharge les séances arrivées dans intervals.icu depuis la montre, avec leur fichier
                d'origine (temps de passage). Sans doublon : relançable autant de fois que voulu (cron).

                  <info>%command.full_name%</info>
                  <info>%command.full_name% --since=2026-09-01</info>

                Configuration : clé intervals.icu dans la page Réglages, ou INTERVALS_API_KEY dans .env.local.
                HELP);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if (!$this->synchronizer->isConfigured()) {
            $io->error('Synchro non configurée : enregistrez la clé intervals.icu dans la page Réglages (ou INTERVALS_API_KEY dans .env.local).');

            return Command::INVALID;
        }

        $since = null;
        $option = $input->getOption('since');
        if (\is_string($option) && '' !== $option) {
            $since = \DateTimeImmutable::createFromFormat('!Y-m-d', $option);
            if (false === $since) {
                $io->error(\sprintf('Date « %s » non reconnue (format AAAA-MM-JJ).', $option));

                return Command::INVALID;
            }
        }

        try {
            $result = $this->synchronizer->sync($since);
        } catch (HttpExceptionInterface $e) {
            $io->error('intervals.icu ne répond pas ou refuse la clé : '.$e->getMessage());

            return Command::FAILURE;
        }

        foreach ($result->import->errors as $error) {
            $io->warning($error);
        }
        $io->success($result->summary());

        return Command::SUCCESS;
    }
}
