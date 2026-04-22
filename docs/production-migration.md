# Production Migration And Recovery Guide

This guide moves the existing native local production system from
Apache/XAMPP/MariaDB into the Docker runtime on this branch.

It is intentionally explicit. Follow the steps in order. Do not copy raw MariaDB
data directories. Use a logical SQL dump.

## 0. What This Migration Does

The migration preserves the current local production database by exporting it
from native MariaDB/XAMPP and importing it into Docker MariaDB.

It does not:

- download models
- download external repositories
- import a synthetic seed dataset
- add a queue or worker system
- make the Docker web UI launch Windows-native jobs

The existing host-side assets remain the source of truth:

- `E:\stock-project\models\sentiment\models\finbert`
- `E:\stock-project\models\kronos\models\Kronos-base`
- `E:\stock-project\repos\Kronos`
- `E:\stock-project\repos\FinGPT`

Adjust the paths if your machine stores those assets elsewhere.

## 1. Prepare The Host Machine

Prerequisites:

- Docker Desktop is installed and running.
- Docker Compose v2 is available.
- The repository is checked out on the Docker branch or after this branch has
  been merged.
- The old XAMPP/MariaDB system is still running for the dump step.
- The model and repo asset folders still exist on the host.

From PowerShell:

```powershell
cd E:\stock-project
git status --short --branch
docker compose version
docker version
```

Expected:

- Git shows the intended Docker branch or the merged target branch.
- Docker commands return versions without errors.

## 2. Verify Required Host Assets

Run this before stopping the old system:

```powershell
Test-Path "E:\stock-project\models\sentiment\models\finbert"
Test-Path "E:\stock-project\models\kronos\models\Kronos-base"
Test-Path "E:\stock-project\models\kronos\tokenizers\Kronos-Tokenizer-base"
Test-Path "E:\stock-project\repos\Kronos"
Test-Path "E:\stock-project\repos\FinGPT"
```

Every command should return `True`.

If one returns `False`, do not continue to final cutover yet. Either restore the
missing asset or point Docker to the correct host path in `docker/prod.env`.

## 3. Create The Production Docker Env File

Create a production env file from the example:

```powershell
Copy-Item docker\prod.env.example docker\prod.env
notepad docker\prod.env
```

Set at least:

```dotenv
COMPOSE_PROJECT_NAME=stock-project-prod
STOCK_DB_NAME=stock_project
STOCK_DB_USER=stock_app
STOCK_DB_PASSWORD=change-me-before-prod
STOCK_DB_ROOT_PASSWORD=change-root-before-prod
STOCK_APP_SECRET=change-this-to-a-long-random-string
STOCK_WEB_PORT=8000
STOCK_JOB_IMAGE=stock-project-job:latest
STOCK_WEB_IMAGE=stock-project-web:latest
STOCK_WEB_CLI_IMAGE=stock-project-migrate:latest
STOCK_MODELS_DIR=E:/stock-project/models
STOCK_KRONOS_DIR=E:/stock-project/repos/Kronos
STOCK_FINGPT_DIR=E:/stock-project/repos/FinGPT
```

Notes:

- Keep `/` slashes in env-file paths. Docker Compose handles them well on Windows.
- Use real secrets instead of the example `change-me` values.
- Use a different `STOCK_WEB_PORT` if port `8000` is already occupied.
- Keep the default image names unless you explicitly want separate prod images.
  Separate dev/prod volumes come from the Compose project name; duplicate images
  are usually wasted disk space on a single machine.

For the remaining commands, set these PowerShell variables to match
`docker/prod.env`:

```powershell
$DockerEnvFile = "docker\prod.env"
$DockerProject = "stock-project-prod"
$DockerDbName = "stock_project"
$DockerDbUser = "stock_app"
$DockerDbPassword = "change-me-before-prod"
```

If you changed `STOCK_DB_NAME`, `STOCK_DB_USER`, or `STOCK_DB_PASSWORD`, update
the matching PowerShell variables before continuing.

Build the Docker app images before stopping XAMPP, so image build/download
problems are found while the old runtime is still available:

```powershell
docker compose --env-file $DockerEnvFile -p $DockerProject build web migrate job
```

## 4. Confirm The Native DB Name And Credentials

The expected native DB name is:

```text
stock_project
```

If your `web/.env.local` uses another DB name, use that name in the dump command
and set the same name in `docker/prod.env`.

Typical XAMPP defaults:

- host: `127.0.0.1`
- port: `3306`
- user: `root`
- password: empty

If your native DB has a password, use it in the dump command.

## 5. Create A Full Logical Dump From Native XAMPP/MariaDB

Create a dump directory:

```powershell
New-Item -ItemType Directory -Force E:\stock-project\db-dumps
```

Set the path to your XAMPP MySQL binaries. Common locations are
`C:\xampp\mysql\bin` or `E:\xampp\mysql\bin`.

```powershell
$XamppMysqlBin = "C:\xampp\mysql\bin"
$DbName = "stock_project"
$Stamp = Get-Date -Format "yyyyMMdd-HHmmss"
$DumpFile = "E:\stock-project\db-dumps\$DbName-$Stamp.sql"
```

Use `cmd /c` for the dump redirection. This avoids Windows PowerShell 5.x
rewriting native command output as UTF-16 text.

For an empty root password:

```powershell
$DumpCommand = "`"$XamppMysqlBin\mysqldump.exe`" --host=127.0.0.1 --port=3306 --user=root --password= --single-transaction --quick --routines --triggers --events --default-character-set=utf8mb4 $DbName > `"$DumpFile`""
cmd /c $DumpCommand
```

For a non-empty password, set the password in the command string:

```powershell
$DumpCommand = "`"$XamppMysqlBin\mysqldump.exe`" --host=127.0.0.1 --port=3306 --user=root --password=YOUR_PASSWORD --single-transaction --quick --routines --triggers --events --default-character-set=utf8mb4 $DbName > `"$DumpFile`""
cmd /c $DumpCommand
```

Verify the dump exists and is not empty:

```powershell
Get-Item $DumpFile
Select-String -Path $DumpFile -Pattern "CREATE TABLE.*instrument" | Select-Object -First 1
Select-String -Path $DumpFile -Pattern "CREATE TABLE.*pipeline_run" | Select-Object -First 1
```

Expected:

- The file size is clearly larger than zero.
- The `CREATE TABLE` checks find matching lines.

Do not delete or modify the native XAMPP database after creating the dump.

## 6. Stop The Old Native Runtime Cleanly

After the dump is verified:

1. Close browser tabs that are using the old web UI.
2. Open the XAMPP Control Panel.
3. Stop Apache.
4. Stop MySQL.
5. Leave the XAMPP data directory untouched.

This is your rollback anchor. If Docker cutover fails, you can start Apache and
MySQL again and return to the old runtime.

## 7. Start Docker MariaDB For Production

Start only the Docker DB first:

```powershell
docker compose --env-file $DockerEnvFile -p $DockerProject up -d db
```

Check that it is healthy:

```powershell
docker compose --env-file $DockerEnvFile -p $DockerProject ps
```

Expected:

- `db` is running.
- The health state eventually becomes healthy.

## 8. Import The Dump Into Docker MariaDB

Use the same `$DumpFile` variable from the dump step, or set it again:

```powershell
$DumpFile = "E:\stock-project\db-dumps\stock_project-YYYYMMDD-HHMMSS.sql"
```

Import into the Docker DB:

```powershell
cmd /c "docker compose --env-file $DockerEnvFile -p $DockerProject exec -T db mariadb -u$DockerDbUser -p$DockerDbPassword $DockerDbName < `"$DumpFile`""
```

Replace:

- `stock_app` if `STOCK_DB_USER` is different
- `change-me-before-prod` with `STOCK_DB_PASSWORD`
- `stock_project` if `STOCK_DB_NAME` is different

If the import fails halfway, reset the Docker production volume and import
again:

```powershell
docker compose --env-file $DockerEnvFile -p $DockerProject down -v
docker compose --env-file $DockerEnvFile -p $DockerProject up -d db
```

Then rerun the import command.

## 9. Run Migrations After Import

After importing the native dump, run migrations so the Docker branch schema is
current:

```powershell
docker compose --env-file $DockerEnvFile -p $DockerProject --profile setup run --rm migrate
```

This is safe after import because Doctrine tracks applied migrations in the DB.
Any migrations already present in the dump are skipped.

## 10. Start The Docker Web Runtime

```powershell
docker compose --env-file $DockerEnvFile -p $DockerProject up -d web
```

Open:

```text
http://127.0.0.1:8000/
```

If you changed `STOCK_WEB_PORT`, use that port instead.

Important:

- Docker web is display/control UI only in this phase.
- Web-triggered Windows-native process launching is disabled.
- Run jobs with `docker compose run` commands below.

## 11. Validate The Imported DB

Check row counts:

```powershell
docker compose --env-file $DockerEnvFile -p $DockerProject exec -T db mariadb -u$DockerDbUser -p$DockerDbPassword $DockerDbName -e "SELECT COUNT(*) AS instruments FROM instrument; SELECT COUNT(*) AS runs FROM pipeline_run; SELECT COUNT(*) AS registry_rows FROM watchlist_candidate_registry;"
```

Open these web pages:

```text
http://127.0.0.1:8000/portfolio
http://127.0.0.1:8000/watchlist
http://127.0.0.1:8000/watchlist-intake
```

Expected:

- The pages load.
- Portfolio/watchlist data matches the old native system.
- Intake registry data is present if it existed before.

## 12. Validate Jobs In Docker

The Docker runtime uses one shared `job` service.

Intake:

```powershell
cmd /c "docker compose --env-file $DockerEnvFile -p $DockerProject --profile jobs run --rm --no-TTY job intake > .tmp\prod-intake.json 2> .tmp\prod-intake.log"
python -m json.tool .tmp\prod-intake.json >NUL
```

SEPA:

```powershell
cmd /c "docker compose --env-file $DockerEnvFile -p $DockerProject --profile jobs run --rm --no-TTY job sepa > .tmp\prod-sepa.json 2> .tmp\prod-sepa.log"
python -m json.tool .tmp\prod-sepa.json >NUL
```

EPA:

```powershell
cmd /c "docker compose --env-file $DockerEnvFile -p $DockerProject --profile jobs run --rm --no-TTY job epa > .tmp\prod-epa.json 2> .tmp\prod-epa.log"
python -m json.tool .tmp\prod-epa.json >NUL
```

Full pipeline:

```powershell
cmd /c "docker compose --env-file $DockerEnvFile -p $DockerProject --profile jobs run --rm --no-TTY job pipeline > .tmp\prod-pipeline.json 2> .tmp\prod-pipeline.log"
python -m json.tool .tmp\prod-pipeline.json >NUL
```

If a job fails:

```powershell
Get-Content .tmp\prod-intake.log -Tail 80
Get-Content .tmp\prod-sepa.log -Tail 80
Get-Content .tmp\prod-epa.log -Tail 80
Get-Content .tmp\prod-pipeline.log -Tail 120
```

## 13. Normal Production Commands After Cutover

Start production:

```powershell
docker compose --env-file $DockerEnvFile -p $DockerProject up -d db web
```

Stop production without deleting data:

```powershell
docker compose --env-file $DockerEnvFile -p $DockerProject stop
```

Run intake:

```powershell
docker compose --env-file $DockerEnvFile -p $DockerProject --profile jobs run --rm job intake
```

Run SEPA:

```powershell
docker compose --env-file $DockerEnvFile -p $DockerProject --profile jobs run --rm job sepa
```

Run EPA:

```powershell
docker compose --env-file $DockerEnvFile -p $DockerProject --profile jobs run --rm job epa
```

Run full pipeline:

```powershell
docker compose --env-file $DockerEnvFile -p $DockerProject --profile jobs run --rm job pipeline
```

## 14. Recovery Playbook

### Docker web does not start

Check logs:

```powershell
docker compose --env-file $DockerEnvFile -p $DockerProject logs web --tail=120
```

Common fixes:

- Port conflict: change `STOCK_WEB_PORT` in `docker/prod.env`.
- DB not healthy: check `docker compose --env-file $DockerEnvFile -p $DockerProject ps`.
- Bad app secret/env file: correct `docker/prod.env`, then restart web.

### Docker DB does not start

Check logs:

```powershell
docker compose --env-file $DockerEnvFile -p $DockerProject logs db --tail=120
```

If this was a failed first import attempt and the native dump is safe, reset the
Docker production volume:

```powershell
docker compose --env-file $DockerEnvFile -p $DockerProject down -v
docker compose --env-file $DockerEnvFile -p $DockerProject up -d db
```

Then import again.

### Import fails

Do not keep a half-imported DB. Reset the Docker production volume and repeat:

```powershell
docker compose --env-file $DockerEnvFile -p $DockerProject down -v
docker compose --env-file $DockerEnvFile -p $DockerProject up -d db
```

Then rerun the import command with the corrected password, DB name, or dump path.

### Pipeline says a model or repo path is missing

Verify the host paths:

```powershell
Test-Path "E:\stock-project\models\sentiment\models\finbert"
Test-Path "E:\stock-project\models\kronos\models\Kronos-base"
Test-Path "E:\stock-project\models\kronos\tokenizers\Kronos-Tokenizer-base"
Test-Path "E:\stock-project\repos\Kronos"
Test-Path "E:\stock-project\repos\FinGPT"
```

If the paths differ, update:

```dotenv
STOCK_MODELS_DIR=...
STOCK_KRONOS_DIR=...
STOCK_FINGPT_DIR=...
```

in `docker/prod.env`.

### Need to roll back to XAMPP

If Docker cutover fails and you need the old runtime back:

1. Stop Docker production:

   ```powershell
   docker compose --env-file $DockerEnvFile -p $DockerProject stop
   ```

2. Open XAMPP Control Panel.
3. Start MySQL.
4. Start Apache.
5. Open the old native web URL.

The old XAMPP database was not modified by the Docker import process.

### Need to inspect Docker DB manually

```powershell
docker compose --env-file $DockerEnvFile -p $DockerProject exec db mariadb -u$DockerDbUser -p$DockerDbPassword $DockerDbName
```

Use the values from `docker/prod.env`.

## 15. Final Acceptance Checklist

Before considering the Docker migration complete:

- Native dump file exists and was verified.
- XAMPP Apache/MySQL were stopped cleanly.
- Docker production DB starts and is healthy.
- Dump imported successfully.
- Migrations ran successfully after import.
- Web UI opens in Docker.
- Portfolio page shows expected data.
- Watchlist page shows expected data.
- Watchlist Intake page shows expected registry data.
- Intake job emits valid JSON.
- SEPA job emits valid JSON.
- EPA job emits valid JSON.
- Full pipeline emits valid JSON.
- Recovery path back to XAMPP is understood and still possible.
