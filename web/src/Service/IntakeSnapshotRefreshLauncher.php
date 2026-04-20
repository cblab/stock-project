<?php

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

class IntakeSnapshotRefreshLauncher
{
    public function __construct(
        #[Autowire('%kernel.project_dir%')]
        private readonly string $webProjectDir,
    ) {
    }

    public function queueForTicker(string $ticker): void
    {
        $ticker = trim($ticker);
        if ($ticker === '') {
            return;
        }

        $projectRoot = dirname($this->webProjectDir);
        $logDir = $this->webProjectDir.DIRECTORY_SEPARATOR.'var'.DIRECTORY_SEPARATOR.'log';
        if (!is_dir($logDir)) {
            mkdir($logDir, 0775, true);
        }

        $python = $this->pythonBinary();
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

        $pythonPath = implode(PATH_SEPARATOR, [
            $projectRoot.DIRECTORY_SEPARATOR.'.deps',
            $projectRoot.DIRECTORY_SEPARATOR.'stock-system'.DIRECTORY_SEPARATOR.'src',
            getenv('PYTHONPATH') ?: '',
        ]);
        $hfHome = $projectRoot.DIRECTORY_SEPARATOR.'.hf-cache';
        $yfinanceCache = $this->webProjectDir.DIRECTORY_SEPARATOR.'var'.DIRECTORY_SEPARATOR.'pipeline-cache'.DIRECTORY_SEPARATOR.'intake_refresh_'.$safeTicker;

        if (!is_dir($yfinanceCache)) {
            mkdir($yfinanceCache, 0775, true);
        }

        putenv('PYTHONPATH='.$pythonPath);
        putenv('HF_HOME='.$hfHome);
        putenv('HUGGINGFACE_HUB_CACHE='.$hfHome.DIRECTORY_SEPARATOR.'hub');
        putenv('TRANSFORMERS_CACHE='.$hfHome.DIRECTORY_SEPARATOR.'transformers');
        putenv('YFINANCE_CACHE_DIR='.$yfinanceCache);

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

    private function pythonBinary(): string
    {
        $configured = getenv('STOCK_PIPELINE_PYTHON');
        if (is_string($configured) && trim($configured) !== '') {
            return trim($configured);
        }

        $default = 'C:\\Python312\\python.exe';

        return is_file($default) ? $default : 'python';
    }
}
