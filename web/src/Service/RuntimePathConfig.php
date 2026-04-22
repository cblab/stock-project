<?php

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

class RuntimePathConfig
{
    public function __construct(
        #[Autowire('%kernel.project_dir%')]
        private readonly string $webProjectDir,
    ) {
    }

    public function webProjectDir(): string
    {
        return $this->normalize($this->webProjectDir);
    }

    public function projectRoot(): string
    {
        return $this->pathFromEnv('PROJECT_ROOT', dirname($this->webProjectDir));
    }

    public function pythonBinary(): string
    {
        $configured = $this->env('PYTHON_BIN') ?: $this->env('STOCK_PIPELINE_PYTHON');
        if ($configured !== null) {
            return $configured;
        }

        return PHP_OS_FAMILY === 'Windows' ? 'python' : 'python3';
    }

    public function stockSystemSrc(): string
    {
        return $this->join($this->projectRoot(), 'stock-system', 'src');
    }

    public function localDepsDir(): string
    {
        return $this->pathFromEnv('LOCAL_DEPS_DIR', $this->join($this->projectRoot(), '.deps'));
    }

    public function modelsDir(): string
    {
        return $this->pathFromEnv('MODELS_DIR', $this->join($this->projectRoot(), 'models'));
    }

    public function kronosDir(): string
    {
        return $this->pathFromEnv('KRONOS_DIR', $this->join($this->projectRoot(), 'repos', 'Kronos'));
    }

    public function fingptDir(): string
    {
        return $this->pathFromEnv('FINGPT_DIR', $this->join($this->projectRoot(), 'repos', 'FinGPT'));
    }

    public function hfHome(): string
    {
        return $this->pathFromEnv('HF_HOME', $this->join($this->projectRoot(), '.hf-cache'));
    }

    public function logDir(): string
    {
        return $this->join($this->webProjectDir(), 'var', 'log');
    }

    public function yfinanceCache(string $name): string
    {
        return $this->join($this->webProjectDir(), 'var', 'pipeline-cache', $name);
    }

    public function webJobLaunchEnabled(): bool
    {
        $value = $this->env('STOCK_WEB_JOB_LAUNCH_ENABLED');
        if ($value === null) {
            return true;
        }

        return !in_array(strtolower($value), ['0', 'false', 'no', 'off'], true);
    }

    public function pythonPath(): string
    {
        return implode(PATH_SEPARATOR, array_filter([
            $this->localDepsDir(),
            $this->stockSystemSrc(),
            $this->env('PYTHONPATH') ?: '',
        ]));
    }

    /** @return array<string, string> */
    public function pythonEnvironment(string $yfinanceCache): array
    {
        $hfHome = $this->hfHome();

        return [
            'PROJECT_ROOT' => $this->projectRoot(),
            'MODELS_DIR' => $this->modelsDir(),
            'KRONOS_DIR' => $this->kronosDir(),
            'FINGPT_DIR' => $this->fingptDir(),
            'PYTHONPATH' => $this->pythonPath(),
            'HF_HOME' => $hfHome,
            'HUGGINGFACE_HUB_CACHE' => $this->join($hfHome, 'hub'),
            'TRANSFORMERS_CACHE' => $this->join($hfHome, 'transformers'),
            'YFINANCE_CACHE_DIR' => $yfinanceCache,
        ];
    }

    public function ensureDirectory(string $path, string $label): void
    {
        if (is_dir($path)) {
            return;
        }
        if (!mkdir($path, 0775, true) && !is_dir($path)) {
            throw new \RuntimeException(sprintf('Unable to create %s directory: %s', $label, $path));
        }
    }

    public function validateForPythonJobs(bool $requireExternalRepos = true): void
    {
        if (!is_dir($this->projectRoot())) {
            throw new \RuntimeException(sprintf('PROJECT_ROOT does not exist: %s', $this->projectRoot()));
        }
        if (!$requireExternalRepos) {
            return;
        }
        if (!is_dir($this->kronosDir())) {
            throw new \RuntimeException(sprintf('KRONOS_DIR does not exist: %s. Set KRONOS_DIR in web/.env.local or create PROJECT_ROOT/repos/Kronos.', $this->kronosDir()));
        }
        if (!is_dir($this->fingptDir())) {
            throw new \RuntimeException(sprintf('FINGPT_DIR does not exist: %s. Set FINGPT_DIR in web/.env.local or create PROJECT_ROOT/repos/FinGPT.', $this->fingptDir()));
        }
    }

    private function pathFromEnv(string $key, string $default): string
    {
        return $this->normalize($this->env($key) ?: $default);
    }

    private function env(string $key): ?string
    {
        foreach ([$_ENV[$key] ?? null, $_SERVER[$key] ?? null, getenv($key)] as $value) {
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return null;
    }

    public function exportEnvironment(string $yfinanceCache): void
    {
        foreach ($this->pythonEnvironment($yfinanceCache) as $key => $value) {
            putenv($key.'='.$value);
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }
    }

    private function join(string ...$parts): string
    {
        $first = array_shift($parts);
        if ($first === null) {
            return '';
        }

        $path = rtrim($first, '\\/');
        foreach ($parts as $part) {
            $path .= DIRECTORY_SEPARATOR.ltrim(rtrim($part, '\\/'), '\\/');
        }

        return $this->normalize($path);
    }

    private function normalize(string $path): string
    {
        return rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path), DIRECTORY_SEPARATOR);
    }
}
