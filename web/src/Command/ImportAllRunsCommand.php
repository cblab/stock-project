<?php

namespace App\Command;

use App\Service\RunImportService;
use App\Service\RuntimePathConfig;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:import-all-runs', description: 'Import all stock pipeline run directories that contain reports/summary.json.')]
class ImportAllRunsCommand extends Command
{
    public function __construct(
        private readonly RunImportService $runImportService,
        private readonly RuntimePathConfig $paths,
    )
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('path', null, InputOption::VALUE_OPTIONAL, 'Runs base directory. Defaults to PROJECT_ROOT/runs.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $basePath = (string) ($input->getOption('path') ?: $this->paths->projectRoot().DIRECTORY_SEPARATOR.'runs');

        if (!is_dir($basePath)) {
            $io->error(sprintf('Runs directory not found: %s', $basePath));
            return Command::FAILURE;
        }

        $imported = 0;
        foreach (new \DirectoryIterator($basePath) as $entry) {
            if (!$entry->isDir() || $entry->isDot()) {
                continue;
            }

            $runPath = str_replace('\\', '/', $entry->getPathname());
            if (!is_file($runPath.'/reports/summary.json')) {
                continue;
            }

            try {
                $this->runImportService->importRun($runPath);
                ++$imported;
                $io->writeln(sprintf('Imported %s', basename($runPath)));
            } catch (\Throwable $error) {
                $io->warning(sprintf('Skipped %s: %s', basename($runPath), $error->getMessage()));
            }
        }

        $io->success(sprintf('Imported %d run(s).', $imported));

        return Command::SUCCESS;
    }
}
