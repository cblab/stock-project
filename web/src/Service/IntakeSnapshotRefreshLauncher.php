<?php

namespace App\Service;

use App\Entity\PipelineRun;
use Doctrine\ORM\EntityManagerInterface;

class IntakeSnapshotRefreshLauncher
{
    public function __construct(
        private readonly RuntimePathConfig $paths,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function queueForTicker(string $ticker): void
    {
        if (!$this->paths->webJobLaunchEnabled()) {
            return;
        }

        $ticker = trim($ticker);
        if ($ticker === '') {
            return;
        }

        $projectRoot = $this->paths->projectRoot();
        $logDir = $this->paths->logDir();
        $this->paths->ensureDirectory($logDir, 'log');

        $python = $this->paths->pythonBinary();
        $this->startJob($projectRoot, $logDir, $python, 'run_sepa.py', $ticker);
        $this->startJob($projectRoot, $logDir, $python, 'run_epa.py', $ticker);
    }

    private function startJob(string $projectRoot, string $logDir, string $python, string $scriptName, string $ticker): void
    {
        $script = $projectRoot.DIRECTORY_SEPARATOR.'stock-system'.DIRECTORY_SEPARATOR.'scripts'.DIRECTORY_SEPARATOR.$scriptName;
        $safeTicker = preg_replace('/[^A-Za-z0-9_.-]+/', '_', $ticker) ?: 'ticker';
        $stamp = (new \DateTimeImmutable())->format('Ymd_His');
        $jobName = pathinfo($scriptName, PATHINFO_FILENAME);
        $stdout = $logDir.DIRECTORY_SEPARATOR.sprintf('%s_%s_%s.out.log', $jobName, $safeTicker, $stamp);
        $stderr = $logDir.DIRECTORY_SEPARATOR.sprintf('%s_%s_%s.err.log', $jobName, $safeTicker, $stamp);
        $run = $this->createRun($jobName, $safeTicker, $stdout, $stderr);

        try {
            $this->paths->validateForPythonJobs(requireExternalRepos: false);
            $yfinanceCache = $this->paths->yfinanceCache('intake_refresh_'.$safeTicker);
            $this->paths->ensureDirectory($yfinanceCache, 'YFinance cache');

            $this->paths->exportEnvironment($yfinanceCache);

            $previousCwd = getcwd();
            try {
                chdir($projectRoot);
                $command = sprintf(
                    'start "" /B %s %s --mode=db --source=all --tickers=%s --tracking-run-id=%d --quiet 1>%s 2>%s',
                    escapeshellarg($python),
                    escapeshellarg($script),
                    escapeshellarg($ticker),
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
                    ->setExitCode($exitCode)
                    ->setFinishedAt(new \DateTimeImmutable())
                    ->setErrorSummary('Snapshot-Refresh konnte nicht gestartet werden.')
                    ->setNotes('Unable to start snapshot refresh process from Symfony.');
                $this->entityManager->flush();
            }
        } catch (\Throwable $error) {
            $run
                ->setStatus('failed')
                ->setExitCode(1)
                ->setFinishedAt(new \DateTimeImmutable())
                ->setErrorSummary($this->summarizeError($error->getMessage()))
                ->setNotes('Unable to start snapshot refresh process from Symfony: '.$error->getMessage());
            $this->entityManager->flush();
        }
    }

    private function createRun(string $jobName, string $safeTicker, string $stdout, string $stderr): PipelineRun
    {
        $now = new \DateTimeImmutable();
        $runKey = sprintf('%s_%s_%s', $jobName, $safeTicker, $now->format('Y-m-d_H-i-s-u'));
        $run = (new PipelineRun())
            ->setRunId($runKey)
            ->setRunKey($runKey)
            ->setRunScope('snapshot_refresh')
            ->setStatus('queued')
            ->setRunPath('')
            ->setCreatedAt($now)
            ->setStdoutLogPath($stdout)
            ->setStderrLogPath($stderr)
            ->setNotes(sprintf('Queued %s snapshot refresh for %s from Watchlist Intake action.', $jobName, $safeTicker));

        $this->entityManager->persist($run);
        $this->entityManager->flush();

        return $run;
    }

    private function summarizeError(string $message): string
    {
        $message = trim(preg_replace('/\s+/', ' ', $message) ?? $message);
        if ($message === '') {
            return 'Unbekannter Startfehler.';
        }

        return substr($message, 0, 512);
    }

}
