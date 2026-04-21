<?php

namespace App\Service;

use App\Entity\PipelineRun;
use Doctrine\ORM\EntityManagerInterface;

class PipelineRunLauncher
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly RuntimePathConfig $paths,
    )
    {
    }

    public function queueRun(string $source = 'portfolio'): PipelineRun
    {
        $source = $source === 'watchlist' ? 'watchlist' : 'portfolio';
        $now = new \DateTimeImmutable();
        $runKey = $now->format('Y-m-d_H-i-s').'-'.$source;
        $projectRoot = $this->paths->projectRoot();
        $logDir = $this->paths->logDir();
        $this->paths->ensureDirectory($logDir, 'log');
        $this->paths->validateForPythonJobs();

        $run = (new PipelineRun())
            ->setRunId($runKey)
            ->setRunKey($runKey)
            ->setRunScope($source)
            ->setStatus('queued')
            ->setRunPath('')
            ->setCreatedAt($now)
            ->setNotes('Queued from Symfony UI.');

        $this->entityManager->persist($run);
        $this->entityManager->flush();

        $script = $projectRoot.DIRECTORY_SEPARATOR.'stock-system'.DIRECTORY_SEPARATOR.'scripts'.DIRECTORY_SEPARATOR.'run_pipeline.py';
        $python = $this->paths->pythonBinary();
        $stdout = $logDir.DIRECTORY_SEPARATOR.'pipeline_run_'.$run->getId().'.out.log';
        $stderr = $logDir.DIRECTORY_SEPARATOR.'pipeline_run_'.$run->getId().'.err.log';
        $yfinanceCache = $this->paths->yfinanceCache('yfinance_run_'.$run->getId());

        try {
            $this->paths->ensureDirectory($yfinanceCache, 'YFinance cache');
            $this->paths->exportEnvironment($yfinanceCache);

            $previousCwd = getcwd();
            try {
                chdir($projectRoot);
                $command = sprintf(
                    'start "" /B %s %s --mode=db --source=%s --run-id=%d --quiet 1>%s 2>%s',
                    escapeshellarg($python),
                    escapeshellarg($script),
                    escapeshellarg($source),
                    $run->getId(),
                    escapeshellarg($stdout),
                    escapeshellarg($stderr),
                );
                $handle = popen($command, 'r');
                $exitCode = is_resource($handle) ? pclose($handle) : 1;
            } finally {
                if (is_string($previousCwd)) {
                    chdir($previousCwd);
                }
            }

            if ($exitCode !== 0) {
                $run
                    ->setStatus('failed')
                    ->setFinishedAt(new \DateTimeImmutable())
                    ->setNotes('Unable to start Python process from Symfony.');
            } else {
                $run->setNotes(sprintf('Started from Symfony UI with %s. Working directory: %s. Logs: %s / %s. Runtime paths come from PROJECT_ROOT/MODELS_DIR/KRONOS_DIR/FINGPT_DIR. YFinance cache: %s.', $python, $projectRoot, $stdout, $stderr, $yfinanceCache));
            }
        } catch (\Throwable $error) {
            $run
                ->setStatus('failed')
                ->setFinishedAt(new \DateTimeImmutable())
                ->setNotes('Unable to start Python process from Symfony: '.$error->getMessage());
        }

        $this->entityManager->flush();

        return $run;
    }

    public function queuePortfolioRun(): PipelineRun
    {
        return $this->queueRun('portfolio');
    }

}
