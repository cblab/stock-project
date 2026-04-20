<?php

namespace App\Command;

use App\Service\RunImportService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:import-run', description: 'Import one stock pipeline run from a run directory.')]
class ImportRunCommand extends Command
{
    public function __construct(private readonly RunImportService $runImportService)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('path', null, InputOption::VALUE_REQUIRED, 'Absolute path to a run directory.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $path = $input->getOption('path');

        if (!is_string($path) || trim($path) === '') {
            $io->error('Please pass --path="E:/stock-project/runs/YYYY-MM-DD_HH-MM".');
            return Command::INVALID;
        }

        try {
            $run = $this->runImportService->importRun($path);
        } catch (\Throwable $error) {
            $io->error($error->getMessage());
            return Command::FAILURE;
        }

        $io->success(sprintf('Imported run %s with %d run items.', $run->getRunKey(), $run->getRunItems()->count()));

        return Command::SUCCESS;
    }
}
