# Agent Exec Proxy

Minimaler Allowlist-Exec-Proxy für stock-project. Ermöglicht Agenten, fest definierte Checks sicher auszuführen.

## Starten

```bash
# Direkt im Container
cd /app && php -S 0.0.0.0:8787 tools/agent-exec-proxy/server.php

# Oder via Docker Compose (für externe Zugriffe)
# Füge zum compose.yaml einen Port hinzu oder verwende exec
```

## Endpunkte

### GET /health
Health-Check, ob der Proxy läuft.

```bash
curl http://localhost:8787/health
```

**Response:**
```json
{
    "status": "ok",
    "timestamp": "2026-04-26T04:15:00+00:00",
    "service": "agent-exec-proxy"
}
```

---

### POST /run/git-status
Git Status im Repo-Root (`/app`).

```bash
curl -X POST http://localhost:8787/run/git-status
```

---

### POST /run/git-diff
Git Diff mit Statistik.

```bash
curl -X POST http://localhost:8787/run/git-diff
```

---

### POST /run/composer-validate
Validiert `composer.json` im Web-Root.

```bash
curl -X POST http://localhost:8787/run/composer-validate
```

---

### POST /run/php-lint
Lint einer einzelnen PHP-Datei unter `/app/web`.

```bash
curl -X POST http://localhost:8787/run/php-lint \
  -H "Content-Type: application/json" \
  -d '{"file": "migrations/Version20260426120000.php"}'
```

**Wichtig:** Pfad muss relativ zu `/app/web` sein. `..` wird abgelehnt.

---

### POST /run/phpunit
Führt den kanonischen Dev-Test-Runner aus.

```bash
curl -X POST http://localhost:8787/run/phpunit \
  -H "Content-Type: application/json" \
  -d '{"path": "tests/Service/Evidence/TradeOutcomeExtractorIntegrationTest.php"}'
```

Request-Regeln:

```text
path ist optional
ohne path: kompletter PHPUnit-Lauf
mit path: nur relativer Pfad unter tests/
keine absoluten Pfade
kein ..
keine freien Shell-Argumente
```

Der Proxy ruft intern den kanonischen Runner auf:

```text
tools/dev/test-web.ps1   (Windows/Host)
tools/dev/test-web.sh    (POSIX)
```

---

### POST /run/doctrine-status
Zeigt Migration-Status.

```bash
curl -X POST http://localhost:8787/run/doctrine-status
```

---

### POST /run/doctrine-dry-run
Führt Migrationen als Dry-Run aus (immer `--dry-run --no-interaction`).

```bash
curl -X POST http://localhost:8787/run/doctrine-dry-run
```

---

### POST /run/lint-container
Lint des Symfony DI-Containers.

```bash
curl -X POST http://localhost:8787/run/lint-container
```

---

### POST /run/composer-dump-autoload
Baut die Composer-Classmap neu und löscht den Symfony prod Cache.

**Wichtig:** Diese Action ist nötig nach neuen PHP-Klassen oder wenn `lint:container` "Expected to find class..." meldet.

```bash
curl -X POST http://localhost:8787/run/composer-dump-autoload
```

**Ablauf:**
1. Führt `composer dump-autoload --classmap-authoritative --no-dev` aus
2. Löscht `/app/web/var/cache/prod` (rekursiv, folgt keinen Symlinks)

**Danach kann `lint:container` erneut laufen:**
```bash
curl -X POST http://localhost:8787/run/lint-container
```

---

## Response-Format

Alle `/run/*`-Endpunkte geben:

```json
{
    "action": "git-status",
    "exit_code": 0,
    "stdout": "string",
    "stderr": "string",
    "duration_ms": 123
}
```

- `exit_code`: 0 = Erfolg, >0 = Fehler
- `stdout/stderr`: Auf 20.000 Zeichen begrenzt
- `duration_ms`: Ausführungszeit in Millisekunden

## Sicherheit

- Nur feste Commands aus Allowlist – keine beliebigen Shell-Befehle
- `php-lint` nur für Dateien unter `/app/web` (Pfadnormalisierung + Traversal-Schutz)
- `doctrine-dry-run` erzwingt immer `--dry-run --no-interaction`
- `composer-dump-autoload` löscht nur `/app/web/var/cache/prod` (fester Pfad, keine Parameter)
- Kein `proc_open` mit Shell-Expansion (Array-Argumente)
- Timeout: 60 Sekunden pro Command
- Keine Ausgabe von Environment-Variablen
- Keine `.env`-Dateien lesbar

## Nicht erlaubt

- Beliebige Commands
- Docker-Befehle
- `rm`, `mysql`, `doctrine:schema:update`
- Echte Migrationen ohne `--dry-run`
- `git push`, `reset`, `clean`
- Pfade außerhalb `/app/web` für php-lint

## Grenzen

- Kein Auth/Token-System (im Container-Netzwerk vorausgesetzt)
- Kein Rate-Limiting
- Keine persistenten Logs
- Keine parallelen Requests (PHP built-in Server)
- Output auf 20k Zeichen pro Stream begrenzt
