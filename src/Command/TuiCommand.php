<?php

declare(strict_types=1);

namespace App\Command;

use App\Tui\MyStravaApp;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:tui', description: 'Ouvre l\'interface terminal MyStrava', aliases: ['tui'])]
final class TuiCommand extends Command
{
    public function __construct(private readonly MyStravaApp $app)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!$input->isInteractive()) {
            $output->writeln('<error>L\'interface terminal nécessite un terminal interactif.</error>');

            return Command::FAILURE;
        }

        $this->app->run();

        return Command::SUCCESS;
    }
}
