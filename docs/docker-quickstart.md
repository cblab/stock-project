# Docker Quickstart

This branch packages the existing system for local Docker use. It does not add
product features and it does not import private data.

## Prerequisites

- Docker Desktop or Docker Engine with Docker Compose v2
- A checkout of this repository
- For full pipeline mode only:
  - local `models/`
  - local `repos/Kronos`
  - local `repos/FinGPT`

The model and repo directories are intentionally host-mounted. They are not
downloaded by Docker and they are not baked into the image.

## Start A Fresh Local Runtime

From the repository root:

```bash
docker compose up -d db
docker compose --profile setup run --rm migrate
```

This starts MariaDB and applies the Symfony/Doctrine schema migrations.

## Run Jobs

```bash
docker compose --profile jobs run --rm job
docker compose --profile jobs run --rm job python stock-system/scripts/run_sepa.py --mode=db --source=all
docker compose --profile jobs run --rm job python stock-system/scripts/run_epa.py --mode=db --source=all
docker compose --profile jobs run --rm job python stock-system/scripts/run_pipeline.py --mode=db --source=all
```

There is one shared Docker job runtime:

- `docker compose --profile jobs run --rm job` runs Watchlist Intake in DB mode by default.
- Pass a different Python command to run SEPA, EPA, or the full pipeline.
- The shared job image includes the full pipeline dependencies, so no separate
  long-term job containers are needed.

## Optional Web UI

```bash
docker compose up -d web
```

Open:

```text
http://127.0.0.1:8000/
```

The Docker web runtime is intentionally launcher-safe. Web-triggered background
job launching is disabled in Docker mode because the legacy launchers are
Windows-native. Run jobs explicitly with the Docker job commands above.

## Empty DB Behavior

A fresh migrated DB has schema but no instruments.

That means:

- `intake` can run and populate the Watchlist Candidate Registry when data is available.
- `sepa`, `epa`, and `pipeline` need at least one active instrument.
- If there are no active instruments, those jobs fail clearly with a message such as `No active instruments found`.

Minimal clean path:

1. Start the optional web UI.
2. Open `http://127.0.0.1:8000/instrument/new`.
3. Create one or more active instruments.
4. Run `sepa`, `epa`, or `pipeline` again.

This branch deliberately does not ship an automatic seed dump. That avoids
hidden side effects and avoids importing personal production data.

## Pipeline Asset Mounts

By default, Compose expects these host paths:

- `./models`
- `./repos/Kronos`
- `./repos/FinGPT`

They are mounted inside the pipeline container as:

- `/app/models`
- `/app/repos/Kronos`
- `/app/repos/FinGPT`

Override the host paths if your assets live elsewhere.

Linux/macOS:

```bash
STOCK_MODELS_DIR=/absolute/path/to/models \
STOCK_KRONOS_DIR=/absolute/path/to/Kronos \
STOCK_FINGPT_DIR=/absolute/path/to/FinGPT \
docker compose --profile jobs run --rm job python stock-system/scripts/run_pipeline.py --mode=db --source=all
```

Windows PowerShell:

```powershell
$env:STOCK_MODELS_DIR = "E:/stock-project/models"
$env:STOCK_KRONOS_DIR = "E:/stock-project/repos/Kronos"
$env:STOCK_FINGPT_DIR = "E:/stock-project/repos/FinGPT"
docker compose --profile jobs run --rm job python stock-system/scripts/run_pipeline.py --mode=db --source=all
```

If required assets are missing, the pipeline should fail with an explicit path
message instead of falling back to host-specific local paths.

## Machine-Readable Output

The Python job scripts print their final JSON payload to stdout unless
`--quiet` is used. Docker Compose status messages and third-party warnings may
appear on stderr.

For machine callers, capture stdout and stderr separately.

Linux/macOS:

```bash
docker compose --profile jobs run --rm --no-TTY job > intake.json 2> intake.log
python -m json.tool intake.json >/dev/null
```

Windows `cmd.exe`:

```cmd
docker compose --profile jobs run --rm --no-TTY job > .tmp\intake.json 2> .tmp\intake.log
python -m json.tool .tmp\intake.json >NUL
```

PowerShell can redirect text with UTF-16 encoding by default, so prefer
`cmd.exe` redirection when validating strict JSON files on Windows.

## Useful Maintenance Commands

Stop containers:

```bash
docker compose stop
```

Remove containers but keep volumes:

```bash
docker compose down
```

Remove the local Docker DB and cache volumes:

```bash
docker compose down -v
```

Use `down -v` only when you intentionally want a fresh empty DB.

## Dev And Prod Separation

For day-to-day development, the default Compose project name is `stock-project`.

For production on the same machine, use a separate project name and env file:

```bash
cp docker/prod.env.example docker/prod.env
docker compose --env-file docker/prod.env -p stock-project-prod up -d db web
```

This keeps development and production containers/volumes separate. See
`docs/production-migration.md` before using Docker as the replacement runtime
for an existing native XAMPP/MariaDB installation.

The app images are explicitly named in `compose.yaml`, so dev and prod can share
the same built images while still using separate DB/cache volumes. This avoids
duplicating the large Python job image just because the Compose project name is
different.
