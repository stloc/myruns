<?php

declare(strict_types=1);

namespace App\Command;

use App\Import\ActivityImporter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsCommand(name: 'app:import', description: 'Importe les activités depuis un export Strava (ZIP/dossier) ou un dossier GPX/TCX')]
final class ImportCommand extends Command
{
    public function __construct(
        private readonly ActivityImporter $importer,
        #[Autowire('%env(STRAVA_EXPORT_PATH)%')] private readonly string $defaultPath,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('path', InputArgument::OPTIONAL, 'Archive export_XXXX.zip, dossier décompressé ou dossier de traces (défaut : STRAVA_EXPORT_PATH)')
            ->setHelp(<<<'HELP'
                Importe (ou ré-importe sans doublon) les activités :

                  <info>%command.full_name% ~/Téléchargements/export_12345678.zip</info>
                  <info>%command.full_name% ~/Dropbox/Apps/tapiriik</info>

                Sans argument, le chemin de la variable STRAVA_EXPORT_PATH est utilisé : pratique en cron.
                HELP);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $argument = $input->getArgument('path');
        $path = \is_string($argument) && '' !== $argument ? $argument : $this->defaultPath;

        if ('' === $path) {
            $io->error('Indiquez un chemin, ou renseignez STRAVA_EXPORT_PATH dans .env.local.');

            return Command::INVALID;
        }

        $progress = null;
        try {
            $result = $this->importer->import($path, static function (int $done, int $total) use ($io, &$progress): void {
                if (null === $progress) {
                    $io->text('Lecture des traces (relief et ville via l\'IGN : quelques secondes par trace la première fois)…');
                    $progress = $io->createProgressBar($total);
                }
                $progress->setProgress($done);
            });
            if (null !== $progress) {
                $progress->finish();
                $io->newLine(2);
            }
        } catch (\InvalidArgumentException|\RuntimeException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        foreach ($result->errors as $error) {
            $io->warning($error);
        }
        $io->success($result->summary());

        return Command::SUCCESS;
    }
}
