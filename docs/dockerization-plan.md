# Dockerization Assessment And Plan

This branch is a dedicated Dockerization track. It intentionally does not add product features.

For local user-facing startup commands, see `docs/docker-quickstart.md`. For
native XAMPP/MariaDB to Docker production migration, see
`docs/production-migration.md`. This file keeps the assessment and
implementation notes.

## 1. Findings

### Python runtime entrypoints

- `stock-system/scripts/run_watchlist_intake.py`
  - Primary OpenClaw integration target for this Docker phase.
  - Runs `SectorWatchlistIntakeEngine` in DB mode.
  - Emits JSON to stdout unless `--quiet` is used.
  - Tracebacks and warnings go to stderr, which keeps stdout machine-consumable in the normal success path.
- `stock-system/scripts/run_pipeline.py`
  - DB/json pipeline runner for Kronos, sentiment, and merged decisions.
  - Requires external Kronos / FinGPT repos and local models for the full path.
- `stock-system/scripts/run_sepa.py`
  - DB-only SEPA snapshot runner.
  - Lighter than the full Kronos/Sentiment pipeline.
- `stock-system/scripts/run_epa.py`
  - DB-only EPA snapshot runner.
  - Lighter than the full Kronos/Sentiment pipeline.

### Symfony/web runtime entrypoints

- `web/public/index.php`
  - HTTP entrypoint for the optional web UI.
- `web/bin/console`
  - Required for Doctrine migrations.
  - This is needed even in a job-first Docker setup because the DB schema currently lives in Symfony migrations.
- Existing web routes include `/portfolio`, `/watchlist`, `/watchlist-intake`, run details, and signal matrices.

### DB dependencies

- Runtime DB is MariaDB/MySQL.
- Python reads DB config from real environment first, then `web/.env.local`, then `web/.env`.
- Symfony uses `DATABASE_URL`.
- Schema is created via Doctrine migrations under `web/migrations`.
- The intake job needs at least these tables to exist:
  - `sector_intake_run`
  - `sector_intake_sector`
  - `sector_intake_candidate`
  - `watchlist_candidate_registry`
  - `instrument`
  - latest signal/snapshot tables when candidate evaluation uses them

### Docker-breaking path/runtime assumptions

- Local paths such as `PROJECT_ROOT`, `MODELS_DIR`, `KRONOS_DIR`, and `FINGPT_DIR` are now env-driven, but Docker must set them to Linux paths like `/app`.
- `web/.env.local` contains host-specific Windows paths and must not be copied into images.
- Full pipeline jobs require mounted or baked model/repo assets:
  - `models/`
  - `repos/Kronos`
  - `repos/FinGPT`
- Intake does not use Kronos/FinGPT directly, but the final Docker branch now uses one shared full job image for operational simplicity.
- Existing cache/log directories are host-local and should become container volumes:
  - yfinance cache
  - future web logs/cache if/when web is containerized

### Windows-specific launcher logic

- `web/src/Service/PipelineRunLauncher.php` uses `start "" /B ... 1>... 2>...`.
- `web/src/Service/IntakeSnapshotRefreshLauncher.php` uses the same Windows shell model.
- This is legacy/native-web behavior and should not be the Linux container runtime model.
- For Docker, OpenClaw or the user should invoke the job container directly in phase 1.
- Later, the web container should trigger Dockerized jobs through an explicit container-safe mechanism, not through Windows `start /B`.

## 2. Proposed Docker Architecture

### Required services

- `db`
  - MariaDB service.
  - Owns persistent DB volume.
- `migrate`
  - One-shot PHP CLI service.
  - Runs Doctrine migrations against `db`.
  - Not a web UI service.
- `job`
  - Shared one-shot Python job service.
  - Runs Watchlist Intake by default.
  - Can run SEPA, EPA, or the full pipeline by passing a different command.
  - Produces JSON on stdout for machine callers when the underlying script does.

### Phase 2 optional services

- `web`
  - Symfony HTTP UI.
  - Added as an optional Linux-container-safe service.
  - Boots Symfony with PHP's built-in server on port `8000`.
  - Connects to the Dockerized DB.
  - Uses `STOCK_WEB_JOB_LAUNCH_ENABLED=0`, so Docker web does not rely on Windows shell launchers.

### Separation of modes

- Job mode:
  - `docker compose --profile jobs run --rm job intake`
  - `docker compose --profile jobs run --rm job sepa`
  - `docker compose --profile jobs run --rm job epa`
  - `docker compose --profile jobs run --rm job pipeline`
- Setup mode:
  - `docker compose run --rm migrate`
  - Applies schema.
- Web mode:
  - Optional later profile.
  - Read/display first, container-safe job launching later.

## 3. Phase Plan

### Phase 1: Minimum viable Docker intake

Target:

```bash
docker compose up -d db
docker compose --profile setup run --rm migrate
docker compose --profile jobs run --rm job
```

Expected result:

- DB runs in Docker.
- Doctrine schema exists.
- Intake job runs in Docker.
- Intake result is valid JSON on stdout.

### Phase 2: Optional web container

Target:

- Add a Symfony web container.
- Keep it secondary to the intake JSON job.
- Make launcher behavior container-safe before exposing job buttons as Docker-native.

Current branch state:

```bash
docker compose up -d db web
```

Open:

```text
http://127.0.0.1:8000/
```

In Docker web mode, job launch buttons are disabled and the Watchlist Intake add path does not spawn SEPA/EPA refresh jobs. Jobs should still be run explicitly through Docker job services, for example:

```bash
docker compose --profile jobs run --rm job
```

### Phase 3: Full job coverage

Current branch state:

- `job` is the shared Python runtime for intake, SEPA, EPA, and full pipeline.
- The job image includes Torch, Transformers, PEFT, Safetensors, Accelerate, and Einops because the full pipeline needs them.
- The full Python image installs `torch==2.6.0+cpu` explicitly from the PyTorch CPU wheel index to avoid pulling GPU-heavy packages into the Linux container runtime.
- Large model/repository assets are not baked into the image. They are provided as host bind mounts.

Required command flow:

```bash
docker compose up -d db
docker compose --profile setup run --rm migrate
docker compose --profile jobs run --rm job intake
docker compose --profile jobs run --rm job sepa
docker compose --profile jobs run --rm job epa
docker compose --profile jobs run --rm job pipeline
```

Pipeline asset defaults:

- host `./models` is mounted read-only to container `/app/models`
- host `./repos/Kronos` is mounted read-only to container `/app/repos/Kronos`
- host `./repos/FinGPT` is mounted read-only to container `/app/repos/FinGPT`
- Hugging Face runtime cache uses the named volume `hf_cache`
- yfinance cache uses the named volume `yfinance_cache`

Override the host-side asset locations when your checkout keeps these assets elsewhere:

```bash
STOCK_MODELS_DIR=/absolute/path/to/models \
STOCK_KRONOS_DIR=/absolute/path/to/Kronos \
STOCK_FINGPT_DIR=/absolute/path/to/FinGPT \
docker compose --profile jobs run --rm job pipeline
```

On Windows PowerShell:

```powershell
$env:STOCK_MODELS_DIR = "E:/stock-project/models"
$env:STOCK_KRONOS_DIR = "E:/stock-project/repos/Kronos"
$env:STOCK_FINGPT_DIR = "E:/stock-project/repos/FinGPT"
docker compose --profile jobs run --rm job pipeline
```

Container-side runtime variables are fixed to Linux paths:

- `PROJECT_ROOT=/app`
- `MODELS_DIR=/app/models`
- `KRONOS_DIR=/app/repos/Kronos`
- `FINGPT_DIR=/app/repos/FinGPT`
- `HF_HOME=/app/.hf-cache`
- `YFINANCE_CACHE_DIR=/app/var/cache/yfinance`

If any required pipeline asset is missing, the pipeline should fail clearly during config/model validation rather than falling back to host-specific paths.

### Phase 4: Less-technical install flow

Target:

- One short install document.
- Clear commands.
- Clear "where do I put models/repos" guidance.
- Minimal troubleshooting for DB readiness, migrations, and empty result sets.

## 4. First Implementation Slice

Implemented in the first slice:

- Added root-level `compose.yaml`.
- Added a Dockerized MariaDB service named `db`.
- Initially added a small Python intake job image. This was later replaced by the shared `job` runtime.
- Added a PHP CLI migration image under `docker/web-cli/Dockerfile`.
- Initially added intake-only requirements. This was later removed when the branch converged on one shared full job image.
- Added `.dockerignore` to avoid copying local caches, host `.env.local`, models, repos, logs, and vendor directories into images.

This is intentionally not a full web-container implementation yet.

Implemented in the second slice:

- Added optional `web` service to the root `compose.yaml`.
- Added `docker/web/Dockerfile`.
- Added `STOCK_WEB_JOB_LAUNCH_ENABLED=0` to Docker web runtime.
- Gated web-triggered job launch paths so Docker web does not call Windows-native launcher behavior.
- Dashboard start buttons are disabled in Docker web mode.
- Watchlist Intake manual add still updates DB state, but skips the automatic SEPA/EPA refresh launcher in Docker web mode.

Implemented in the third slice:

- Added Docker job services for `sepa`, `epa`, and `pipeline` as an intermediate slice.
- Kept `intake`, `sepa`, and `epa` on the light Python image during that intermediate slice.
- Added `docker/python-full/Dockerfile` for full pipeline dependencies.
- Added read-only host bind mounts for `models`, `repos/Kronos`, and `repos/FinGPT`.
- Added `hf_cache` as a named volume for Hugging Face/runtime cache.
- Redirected Kronos model-load chatter to stderr so `run_pipeline.py` can keep stdout machine-readable for JSON callers.
- Preserved the optional Docker web runtime as launcher-safe; web-triggered Windows-native job launch remains disabled in Docker mode.

Implemented in the fourth slice:

- Converged the per-job Compose services into one shared `job` service.
- Removed the unused light Python Dockerfile and intake-only requirements file.
- Added env-driven DB credentials, app secret, and web port for dev/prod separation.
- Added `docker/prod.env.example`.
- Added a detailed production migration and recovery guide for native XAMPP/MariaDB to Docker.

Validation performed on this branch:

```bash
docker compose config
docker compose --profile setup --profile jobs config
docker compose --profile jobs build job
docker compose --profile setup build migrate
docker compose up -d db
docker compose --profile setup run --rm migrate
docker compose --profile jobs run --rm job intake
docker compose up -d db web
docker compose --profile jobs run --rm job sepa
docker compose --profile jobs run --rm job epa
docker compose --profile jobs run --rm job pipeline
docker compose up -d web
```

The intake, SEPA, EPA, and pipeline scripts emit their final JSON payload on stdout unless `--quiet` is used. Docker Compose container-status messages and third-party runtime/model-load warnings may appear on stderr, so machine callers that need strict JSON should capture stdout separately from stderr.

## 5. Exact Files Created Or Changed

- `.dockerignore`
- `compose.yaml`
- `docker/python-full/Dockerfile`
- `docker/prod.env.example`
- `docker/web-cli/Dockerfile`
- `docker/web/Dockerfile`
- `stock-system/src/kronos/wrapper.py`
- `docs/docker-quickstart.md`
- `docs/production-migration.md`
- `docs/dockerization-plan.md`
- `web/src/Service/RuntimePathConfig.php`
- `web/src/Controller/DashboardController.php`
- `web/src/Service/IntakeSnapshotRefreshLauncher.php`
- `web/templates/base.html.twig`
- `web/templates/dashboard/index.html.twig`

## 6. Risks / Open Questions

- A fresh DB will be schema-empty until `migrate` is run.
- A schema-only DB may still have no instruments; SEPA/EPA/pipeline DB mode require active instruments.
- `yfinance` can emit warnings/errors to stderr; successful JSON remains on stdout.
- Full pipeline Docker support requires host-mounted model/repo assets for Kronos and FinGPT. This branch intentionally does not download or bake those assets into the image.
- Symfony web launch buttons are not Docker-native yet because the current launchers use Windows shell semantics.
- Docker web intentionally disables web-triggered job starts; Docker-native job orchestration is deferred.
- The existing `web/compose.yaml` is Symfony-generated DB scaffolding; the root `compose.yaml` is the Dockerization branch target for the full project.
