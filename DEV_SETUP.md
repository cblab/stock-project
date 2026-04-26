# Dev-Setup für stock-project

Schnelles UI-Testing ohne Prod-Image-Rebuild.

## Ports

| Umgebung | Port | Projektname |
|----------|------|-------------|
| **Prod** | 8000 | `stock-project` |
| **Dev**  | 8001 | `stock-project-dev` |

Beide können parallel laufen. Prod bleibt stabil, Dev ist für schnelle Iterationen.

## Voraussetzungen

- Docker + Docker Compose
- MariaDB läuft (wird von Base-Compose bereitgestellt)

## Start

```bash
# Dev-Webserver starten (DB aus Base-Compose)
docker compose --env-file docker/prod.env -p stock-project-dev -f compose.yaml -f docker/compose.dev.yml up -d web

# Oder mit zusätzlichem phpMyAdmin für Dev
docker compose --env-file docker/prod.env -p stock-project-dev -f compose.yaml -f docker/compose.dev.yml up -d web db phpmyadmin
```

App ist dann unter http://127.0.0.1:8001 erreichbar.

## Stop

```bash
# Dev-Stack stoppen
docker compose -p stock-project-dev -f compose.yaml -f docker/compose.dev.yml down

# Nur den Dev-Web-Container stoppen
docker compose -p stock-project-dev stop web
```

## Twig-Lint

```bash
# Im laufenden Dev-Container
docker compose -p stock-project-dev exec web php bin/console lint:twig templates/

# Oder lokal via Composer (falls PHP lokal installiert)
cd web && php bin/console lint:twig templates/
```

## Cache Clear

```bash
# Im laufenden Dev-Container
docker compose -p stock-project-dev exec web php bin/console cache:clear

# Oder lokal
cd web && php bin/console cache:clear
```

## Hot-Reload

Dank selektiver Mounts in `docker/compose.dev.yml` sind Änderungen an folgenden Pfaden **ohne Image-Rebuild** sofort sichtbar:

- `web/templates/` → Twig-Templates
- `web/src/` → PHP-Src
- `web/config/` → Konfiguration
- `web/assets/` → Assets
- `web/migrations/` → Doctrine-Migrations

**Nicht** gemountet (deshalb Prod-kompatibel):
- `vendor/` → Bleibt im Image
- `var/` → Named Volume für Cache/Logs

## Fehlersuche

```bash
# Container-Logs
docker compose -p stock-project-dev logs -f web

# In den Container shellen
docker compose -p stock-project-dev exec web sh

# Dev-Container neu starten
docker compose -p stock-project-dev restart web
```

## Unterschiede zu Prod

| Aspekt | Prod | Dev |
|--------|------|-----|
| `APP_ENV` | `prod` | `dev` |
| `APP_DEBUG` | `0` | `1` |
| Webserver | Apache/mod_php | PHP Built-in Server |
| Mounts | Keine (immutable) | Selektive Hot-Reload-Mounts |
| Port | 8000 | 8001 |

**Wichtig:** Die Dev-Umgebung verwendet dasselbe Docker-Image wie Prod – nur mit überschriebenen Entrypoint/Environment.
