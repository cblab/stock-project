<?php

namespace App\Service;

use App\Entity\PipelineRun;
use Doctrine\ORM\EntityManagerInterface;

class PipelineRunLauncher
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    public function queuePortfolioRun(string $projectRoot): PipelineRun
    {
        $now = new \DateTimeImmutable();
        $runKey = $now->format('Y-m-d_H-i-s');
        $projectRoot = rtrim(str_replace('\\', DIRECTORY_SEPARATOR, $projectRoot), DIRECTORY_SEPARATOR);
        $logDir = $projectRoot.DIRECTORY_SEPARATOR.'web'.DIRECTORY_SEPARATOR.'var'.DIRECTORY_SEPARATOR.'log';
        if (!is_dir($logDir)) {
            mkdir($logDir, 0775, true);
        }

        $run = (new PipelineRun())
            ->setRunId($runKey)
            ->setRunKey($runKey)
            ->setStatus('queued')
            ->setRunPath('')
            ->setCreatedAt($now)
            ->setNotes('Queued from Symfony UI.');

        $this->entityManager->persist($run);
        $this->entityManager->flush();

        $script = $projectRoot.DIRECTORY_SEPARATOR.'stock-system'.DIRECTORY_SEPARATOR.'scripts'.DIRECTORY_SEPARATOR.'run_pipeline.py';
        $python = $this->pythonBinary();
        $stdout = $logDir.DIRECTORY_SEPARATOR.'pipeline_run_'.$run->getId().'.out.log';
        $stderr = $logDir.DIRECTORY_SEPARATOR.'pipeline_run_'.$run->getId().'.err.log';
        $pythonPath = implode(PATH_SEPARATOR, [
            $projectRoot.DIRECTORY_SEPARATOR.'.deps',
            $projectRoot.DIRECTORY_SEPARATOR.'stock-system'.DIRECTORY_SEPARATOR.'src',
            getenv('PYTHONPATH') ?: '',
        ]);
        $hfHome = $projectRoot.DIRECTORY_SEPARATOR.'.hf-cache';
        $yfinanceCache = $projectRoot.DIRECTORY_SEPARATOR.'web'.DIRECTORY_SEPARATOR.'var'.DIRECTORY_SEPARATOR.'pipeline-cache'.DIRECTORY_SEPARATOR.'yfinance_run_'.$run->getId();
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
            'start "" /B %s %s --mode=db --source=portfolio --run-id=%d --quiet 1>%s 2>%s',
            escapeshellarg($python),
            escapeshellarg($script),
            $run->getId(),
            escapeshellarg($stdout),
            escapeshellarg($stderr),
        );
        $handle = popen($command, 'r');
        $exitCode = is_resource($handle) ? pclose($handle) : 1;
        if (is_string($previousCwd)) {
            chdir($previousCwd);
        }

        if ($exitCode !== 0) {
            $run
                ->setStatus('failed')
                ->setFinishedAt(new \DateTimeImmutable())
                ->setNotes('Unable to start Python process from Symfony.');
        } else {
            $run->setNotes(sprintf('Started from Symfony UI with %s. Working directory: %s. Logs: %s / %s. PYTHONPATH includes .deps and stock-system/src. YFinance cache: %s.', $python, $projectRoot, $stdout, $stderr, $yfinanceCache));
        }

        $this->entityManager->flush();

        return $run;
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
