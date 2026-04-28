<?php
/**
 * Agent Exec Proxy - Minimal Allowlist Command Runner
 *
 * Starten: php -S 0.0.0.0:8787 server.php
 *
 * Dieser Proxy bietet eine sichere Möglichkeit für Agenten,
 * fest definierte Checks auszuführen, ohne beliebige Shell-Befehle
 * zuzulassen.
 */

// Konfiguration
const TIMEOUT_SECONDS = 60;
const PHPUNIT_TIMEOUT_SECONDS = 900;
const MAX_OUTPUT_LENGTH = 20000;
const REPO_ROOT = __DIR__ . '/../..';
const WEB_ROOT = __DIR__ . '/../../web';

// Allowlist der erlaubten Commands
// Format: method => [path => [name, workingDir, commandArray]]
$ALLOWED_COMMANDS = [
    'GET' => [
        '/health' => ['health', null, null],
    ],
    'POST' => [
        '/run/git-status' => ['git-status', REPO_ROOT, ['git', 'status', '--short']],
        '/run/git-diff' => ['git-diff', REPO_ROOT, ['git', 'diff', '--stat']],
        '/run/composer-validate' => ['composer-validate', WEB_ROOT, ['composer', 'validate', '--no-check-publish']],
        '/run/php-lint' => ['php-lint', WEB_ROOT, null], // Wird dynamisch gebaut
        '/run/phpunit' => ['phpunit', REPO_ROOT, null], // Nutzt kanonischen Runner
        '/run/doctrine-status' => ['doctrine-status', WEB_ROOT, ['php', 'bin/console', 'doctrine:migrations:status']],
        '/run/doctrine-dry-run' => ['doctrine-dry-run', WEB_ROOT, ['php', 'bin/console', 'doctrine:migrations:migrate', '--dry-run', '--no-interaction']],
        '/run/lint-container' => ['lint-container', WEB_ROOT, ['php', 'bin/console', 'lint:container']],
        '/run/composer-dump-autoload' => ['composer-dump-autoload', WEB_ROOT, null], // Wird mit Cache-Delete behandelt
    ],
];

/**
 * Sendet JSON-Response und beendet das Script
 */
function jsonResponse(int $httpCode, array $data): void {
    http_response_code($httpCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * Führt ein Command aus und gibt das Ergebnis zurück
 */
function runCommand(string $name, string $workingDir, array $command, int $timeoutSeconds = TIMEOUT_SECONDS): array {
    $startTime = hrtime(true);

    $descriptors = [
        0 => ['pipe', 'r'],  // stdin
        1 => ['pipe', 'w'],  // stdout
        2 => ['pipe', 'w'],  // stderr
    ];

    $process = proc_open($command, $descriptors, $pipes, $workingDir, null, ['bypass_shell' => true]);

    if (!is_resource($process)) {
        return [
            'action' => $name,
            'exit_code' => -1,
            'stdout' => '',
            'stderr' => 'Failed to start process',
            'duration_ms' => 0,
        ];
    }

    // stdin sofort schließen (wir schicken keine Input)
    fclose($pipes[0]);

    // Streams auf non-blocking setzen
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);

    $stdout = '';
    $stderr = '';
    $timeoutAt = microtime(true) + $timeoutSeconds;

    while (true) {
        $status = proc_get_status($process);

        if (!$status['running']) {
            // Restliche Daten lesen
            while (!feof($pipes[1])) {
                $stdout .= fread($pipes[1], 8192);
            }
            while (!feof($pipes[2])) {
                $stderr .= fread($pipes[2], 8192);
            }
            break;
        }

        // Daten lesen wenn verfügbar
        $stdout .= fread($pipes[1], 8192);
        $stderr .= fread($pipes[2], 8192);

        // Output-Limits prüfen
        if (strlen($stdout) > MAX_OUTPUT_LENGTH * 2) {
            proc_terminate($process, 9);
            $stdout = substr($stdout, 0, MAX_OUTPUT_LENGTH) . "\n[OUTPUT TRUNCATED - exceeded limit]";
        }
        if (strlen($stderr) > MAX_OUTPUT_LENGTH * 2) {
            proc_terminate($process, 9);
            $stderr = substr($stderr, 0, MAX_OUTPUT_LENGTH) . "\n[OUTPUT TRUNCATED - exceeded limit]";
        }

        // Timeout prüfen
        if (microtime(true) > $timeoutAt) {
            proc_terminate($process, 9);
            $stderr .= "\n[TIMEOUT - process killed after " . $timeoutSeconds . "s]";
            break;
        }

        usleep(10000); // 10ms warten
    }

    fclose($pipes[1]);
    fclose($pipes[2]);

    $exitCode = proc_close($process);
    $durationMs = intval((hrtime(true) - $startTime) / 1e6);

    // Final output truncation
    if (strlen($stdout) > MAX_OUTPUT_LENGTH) {
        $stdout = substr($stdout, 0, MAX_OUTPUT_LENGTH) . "\n[OUTPUT TRUNCATED]";
    }
    if (strlen($stderr) > MAX_OUTPUT_LENGTH) {
        $stderr = substr($stderr, 0, MAX_OUTPUT_LENGTH) . "\n[OUTPUT TRUNCATED]";
    }

    return [
        'action' => $name,
        'exit_code' => $exitCode,
        'stdout' => $stdout,
        'stderr' => $stderr,
        'duration_ms' => $durationMs,
    ];
}

/**
 * Löscht ein Verzeichnis rekursiv (nur für feste Cache-Pfade).
 * Folgt keinen Symlinks.
 *
 * @param string $path Der zu löschende Pfad
 * @return array ['success' => bool, 'error' => string|null]
 */
function deleteDirectory(string $path): array {
    // WICHTIG: Zuerst auf Symlink prüfen, BEVOR realpath() aufgerufen wird!
    // realpath() löst Symlinks auf, daher würde is_link() danach nie true zurückgeben.
    if (is_link($path)) {
        if (!unlink($path)) {
            return ['success' => false, 'error' => 'Failed to unlink symlink: ' . $path];
        }
        return ['success' => true, 'error' => null];
    }

    // Pfad normalisieren
    $realPath = realpath($path);
    if ($realPath === false) {
        // Verzeichnis existiert nicht - das ist OK (rm -rf Semantik)
        return ['success' => true, 'error' => null];
    }

    // Strikte Prüfung: Pfad muss unterhalb von WEB_ROOT/var/cache liegen
    $cacheBase = realpath(WEB_ROOT . '/var/cache');
    if ($cacheBase === false) {
        // Cache-Verzeichnis existiert nicht - das ist OK (rm -rf Semantik)
        return ['success' => true, 'error' => null];
    }

    // Pfad muss mit cacheBase beginnen
    if (strpos($realPath, $cacheBase . DIRECTORY_SEPARATOR) !== 0 && $realPath !== $cacheBase) {
        return ['success' => false, 'error' => 'Path is outside allowed cache directory: ' . $path];
    }

    // Verzeichnis lesen und rekursiv löschen
    $items = scandir($realPath);
    if ($items === false) {
        return ['success' => false, 'error' => 'Failed to read directory: ' . $path];
    }

    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }

        $itemPath = $realPath . DIRECTORY_SEPARATOR . $item;

        // Prüfe auf Symlink - nicht folgen
        if (is_link($itemPath)) {
            if (!unlink($itemPath)) {
                return ['success' => false, 'error' => 'Failed to unlink symlink: ' . $itemPath];
            }
            continue;
        }

        if (is_dir($itemPath)) {
            $result = deleteDirectory($itemPath);
            if (!$result['success']) {
                return $result;
            }
        } else {
            if (!unlink($itemPath)) {
                return ['success' => false, 'error' => 'Failed to delete file: ' . $itemPath];
            }
        }
    }

    // Verzeichnis selbst löschen
    if (!rmdir($realPath)) {
        return ['success' => false, 'error' => 'Failed to remove directory: ' . $path];
    }

    return ['success' => true, 'error' => null];
}

/**
 * Normalisiert und validiert einen Dateipfad für php-lint
 */
function normalizeLintPath(string $inputPath): ?string {
    // Basis-Verzeichnis
    $baseDir = realpath(WEB_ROOT);
    if ($baseDir === false) {
        return null;
    }

    // Pfad normalisieren - entferne \0 und andere gefährliche Zeichen
    $cleanPath = str_replace(["\0", "\r", "\n"], '', $inputPath);

    // Keine Directory Traversal erlauben
    if (strpos($cleanPath, '..') !== false) {
        return null;
    }

    // Absoluten Pfad bauen und normalisieren
    $fullPath = $baseDir . DIRECTORY_SEPARATOR . $cleanPath;
    $realPath = realpath($fullPath);

    // Pfad muss existieren und innerhalb von WEB_ROOT liegen
    if ($realPath === false) {
        // Datei existiert nicht - prüfe ob der Pfad theoretisch im erlaubten Bereich wäre
        $normalized = $baseDir . DIRECTORY_SEPARATOR . ltrim($cleanPath, DIRECTORY_SEPARATOR);
        // Prüfe ob es mit baseDir startet
        if (strpos($normalized, $baseDir . DIRECTORY_SEPARATOR) !== 0 && $normalized !== $baseDir) {
            return null;
        }
        return $normalized; // Nicht-existierender Pfad, aber im erlaubten Bereich
    }

    // Strikte Prüfung: Pfad muss unterhalb von WEB_ROOT liegen
    if (strpos($realPath, $baseDir . DIRECTORY_SEPARATOR) !== 0 && $realPath !== $baseDir) {
        return null;
    }

    return $realPath;
}

/**
 * Validiert einen optionalen PHPUnit-Testpfad relativ zu web/tests.
 *
 * @return string|false|null Null = alle Tests, false = ungültig, string = validierter relativer Pfad
 */
function normalizePhpUnitPath(?string $inputPath): string|false|null {
    if ($inputPath === null || $inputPath === '') {
        return null;
    }

    $cleanPath = str_replace(["\0", "\r", "\n"], '', $inputPath);
    $cleanPath = str_replace('\\', '/', $cleanPath);

    if ($cleanPath === '' || str_starts_with($cleanPath, '/') || strpos($cleanPath, '..') !== false) {
        return false;
    }

    if (!str_starts_with($cleanPath, 'tests/')) {
        return false;
    }

    return $cleanPath;
}

/**
 * Liest den JSON-Request-Body
 */
function getJsonInput(): ?array {
    $input = file_get_contents('php://input');
    if ($input === false || empty($input)) {
        return null;
    }
    $decoded = json_decode($input, true);
    return is_array($decoded) ? $decoded : null;
}

// === Haupt-Routing ===

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$uri = $_SERVER['REQUEST_URI'] ?? '/';

// Query-String entfernen
$path = parse_url($uri, PHP_URL_PATH);

// Route finden
if (!isset($ALLOWED_COMMANDS[$method][$path])) {
    jsonResponse(404, [
        'error' => 'Not found',
        'method' => $method,
        'path' => $path,
    ]);
}

[$name, $workingDir, $command] = $ALLOWED_COMMANDS[$method][$path];

// Health-Check ist einfach
if ($name === 'health') {
    jsonResponse(200, [
        'status' => 'ok',
        'timestamp' => date('c'),
        'service' => 'agent-exec-proxy',
    ]);
}

// Spezialfall: php-lint braucht dynamischen Pfad
if ($name === 'php-lint') {
    $input = getJsonInput();
    if (!isset($input['file']) || !is_string($input['file'])) {
        jsonResponse(400, [
            'error' => 'Missing or invalid "file" in JSON body',
            'example' => ['file' => 'migrations/Version20260426XXXXXX.php'],
        ]);
    }

    $filePath = normalizeLintPath($input['file']);
    if ($filePath === null) {
        jsonResponse(403, [
            'error' => 'Invalid file path. Path must be within /app/web and not contain ".."',
            'requested' => $input['file'],
        ]);
    }

    // Command mit dem validierten Pfad bauen
    $command = ['php', '-l', $filePath];
}

// Spezialfall: phpunit nutzt den kanonischen Test-Runner
if ($name === 'phpunit') {
    $input = getJsonInput() ?? [];
    $requestedPath = isset($input['path']) && is_string($input['path']) ? $input['path'] : null;
    $normalizedPath = normalizePhpUnitPath($requestedPath);

    if ($normalizedPath === false) {
        jsonResponse(400, [
            'error' => 'Invalid "path". Only relative paths under tests/ are allowed; no absolute paths, "..", or free shell arguments.',
            'requested' => $requestedPath,
        ]);
    }

    $psScript = REPO_ROOT . '/tools/dev/test-web.ps1';
    $shScript = REPO_ROOT . '/tools/dev/test-web.sh';

    if (DIRECTORY_SEPARATOR === '\\') {
        $command = ['powershell.exe', '-ExecutionPolicy', 'Bypass', '-File', $psScript];
    } else {
        $command = ['sh', $shScript];
    }

    if ($normalizedPath !== null) {
        $command[] = $normalizedPath;
    }

    $result = runCommand($name, $workingDir, $command, PHPUNIT_TIMEOUT_SECONDS);
    jsonResponse(200, $result);
    exit;
}

// Spezialfall: composer-dump-autoload + Cache-Löschung
if ($name === 'composer-dump-autoload') {
    // Schritt 1: composer dump-autoload ausführen
    $command = ['composer', 'dump-autoload', '--classmap-authoritative', '--no-dev'];
    $result = runCommand($name, $workingDir, $command);

    // Wenn composer fehlschlägt, sofort zurückgeben
    if ($result['exit_code'] !== 0) {
        jsonResponse(200, $result);
    }

    // Schritt 2: Symfony prod Cache löschen
    $cachePath = WEB_ROOT . '/var/cache/prod';
    $deleteResult = deleteDirectory($cachePath);

    if (!$deleteResult['success']) {
        $result['exit_code'] = 1;
        $result['stderr'] .= "\n[Cache delete failed] " . $deleteResult['error'];
    }

    // Ergebnis zurückgeben (composer-Ausgabe + ggf. Cache-Fehler)
    jsonResponse(200, $result);
    exit;
}

// Command ausführen
$result = runCommand($name, $workingDir, $command);

// HTTP-Code basierend auf exit_code (0 = 200, sonst 200 mit error-Info)
// Wir geben immer 200 zurück, aber exit_code zeigt den Erfolg an
jsonResponse(200, $result);
