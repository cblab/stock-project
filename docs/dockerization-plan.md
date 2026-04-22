# Dockerization Assessment And Plan

This branch is a dedicated Dockerization track. It intentionally does not add product features.

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
- The initial intake job does not need Kronos/FinGPT repos as a hard requirement, so it can use a smaller Python image.
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

### Phase 1 required services

- `db`
  - MariaDB service.
  - Owns persistent DB volume.
- `migrate`
  - One-shot PHP CLI service.
  - Runs Doctrine migrations against `db`.
  - Not a web UI service.
- `intake`
  - Python job service.
  - Runs `stock-system/scripts/run_watchlist_intake.py --mode=db`.
  - Produces JSON on stdout for OpenClaw or any caller.

### Phase 2 optional services

- `web`
  - Symfony HTTP UI.
  - Should be added after phase 1 is stable.
  - Must not rely on Windows shell launchers inside Linux containers.
- `pipeline-job`
  - Full Kronos/Sentiment pipeline image or profile.
  - Needs mounted model/repo assets.
- `sepa-job` / `epa-job`
  - Can share the Python job image once command profiles are added.

### Separation of modes

- Job mode:
  - `docker compose run --rm intake`
  - Primary integration path for OpenClaw.
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
docker compose --profile jobs run --rm intake
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

### Phase 3: Full job coverage

Target:

- Add command/profile support for:
  - pipeline
  - SEPA
  - EPA
- Decide how large external assets are supplied:
  - bind mounts
  - named volumes
  - separately built image layer

### Phase 4: Less-technical install flow

Target:

- One short install document.
- Clear commands.
- Clear "where do I put models/repos" guidance.
- Minimal troubleshooting for DB readiness, migrations, and empty result sets.

## 4. First Implementation Slice

Implemented in this first slice:

- Added root-level `compose.yaml`.
- Added a Dockerized MariaDB service named `db`.
- Added a Python intake job image under `docker/python/Dockerfile`.
- Added a PHP CLI migration image under `docker/web-cli/Dockerfile`.
- Added `stock-system/requirements-intake.txt` so the first job image does not need Torch/Transformers/Kronos/FinGPT.
- Added `.dockerignore` to avoid copying local caches, host `.env.local`, models, repos, logs, and vendor directories into images.

This is intentionally not a full web-container implementation yet.

Validation performed on this branch:

```bash
docker compose config
docker compose --profile setup --profile jobs config
docker compose build intake
docker compose --profile setup build migrate
docker compose up -d db
docker compose --profile setup run --rm migrate
docker compose --profile jobs run --rm intake
```

The intake job exited successfully and emitted valid JSON on stdout. Docker Compose container-status messages appeared on stderr, so callers that need strict JSON should capture stdout separately from stderr.

## 5. Exact Files Created Or Changed

- `.dockerignore`
- `compose.yaml`
- `docker/python/Dockerfile`
- `docker/web-cli/Dockerfile`
- `stock-system/requirements-intake.txt`
- `docs/dockerization-plan.md`

## 6. Risks / Open Questions

- A fresh DB will be schema-empty until `migrate` is run.
- A schema-only DB may still have no instruments; intake can return an empty/low-signal result depending on configured sector candidates and data availability.
- `yfinance` can emit warnings/errors to stderr; successful JSON remains on stdout.
- Full pipeline Docker support is blocked by model/repo asset strategy for Kronos and FinGPT.
- Symfony web launch buttons are not Docker-native yet because the current launchers use Windows shell semantics.
- The existing `web/compose.yaml` is Symfony-generated DB scaffolding; the root `compose.yaml` is the Dockerization branch target for the full project.
