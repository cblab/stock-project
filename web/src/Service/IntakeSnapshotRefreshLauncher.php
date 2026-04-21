<?php

namespace App\Service;

class IntakeSnapshotRefreshLauncher
{
    public function __construct(
        private readonly RuntimePathConfig $paths,
    ) {
    }

    public function queueForTicker(string $ticker): void
    {
        $ticker = trim($ticker);
        if ($ticker === '') {
            return;
        }

        $projectRoot = $this->paths->projectRoot();
        $logDir = $this->paths->logDir();
        $this->paths->ensureDirectory($logDir, 'log');
        $this->paths->validateForPythonJobs(requireExternalRepos: false);

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

        $yfinanceCache = $this->paths->yfinanceCache('intake_refresh_'.$safeTicker);
        $this->paths->ensureDirectory($yfinanceCache, 'YFinance cache');

        $this->paths->exportEnvironment($yfinanceCache);

        $previousCwd = getcwd();
        chdir($projectRoot);
        $command = sprintf(
            'start "" /B %s %s --mode=db --source=all --tickers=%s --quiet 1>%s 2>%s',
            escapeshellarg($python),
            escapeshellarg($script),
            escapeshellarg($ticker),
            escapeshellarg($stdout),
            escapeshellarg($stderr),
        );
        $handle = popen($command, 'r');
        if (is_resource($handle)) {
            pclose($handle);
        }
        if (is_string($previousCwd)) {
            chdir($previousCwd);
        }
    }

}
