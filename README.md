# stock-project

Lokale Research- und Entscheidungsplattform für Aktienselektion mit drei Ebenen:

1. **Discovery / Intake**
   - sektorbasierte Kandidatenfindung
   - Candidate Registry
   - manuelle Übernahme in die Watchlist

2. **Buy-Side**
   - Kronos
   - Sentiment
   - Merged Score
   - **SEPA** = *Specific Entry Point Analysis*
   - historische Buy-Signal-Snapshots für **K / S / M**
   - Buy Signal Matrix

3. **Sell-Side**
   - **EPA** = *Exit Point Analysis*
   - Sell Signal Matrix

Die Weboberfläche zeigt Portfolio, Watchlist, Intake, Instrument-Detailseiten sowie Buy- und Sell-Signalansichten.

---

## Zweck

`stock-project` ist **kein generischer Trading-Bot**.  
Es ist ein lokales System für:

- Research
- Scoring
- Watchlist- und Intake-Workflows
- Snapshotting historischer Signale
- Entscheidungsunterstützung

Die eigentliche Analyse läuft in Python.  
Symfony ist die Anzeige- und Orchestrierungsschicht.  
MariaDB ist der zentrale Persistenz-Layer.

---

## Architektur in Kurzform

| Ebene | Stack | Rolle |
|---|---|---|
| Web UI | Symfony / PHP / Twig | Dashboards, Instrumente, Watchlist, Signalansichten |
| Analytics Backend | Python | Intake, Pipeline, SEPA, EPA, Snapshot-Jobs, Backfills |
| Persistenz | MariaDB | Instrumente, Pipeline-Runs, Snapshots, Candidate Registry, Preis-Historie |
| Infrastruktur | Docker Compose | DB, Web, Migrationen, Python-Job-Runtime |

**Wichtige Trennung:**

- `web/` = Symfony-Anwendung und Doctrine-Migrationen
- `stock-system/` = Python-Analyse und Job-Runtime
- `compose.yaml` = zentrale Docker-Orchestrierung
- `models/` und `repos/` = lokale/externe Assets, nicht die primäre Anwendungslogik

Die kuratierte Architekturübersicht liegt in:

- `docs/stock-project-project-map.md`

Wenn README, Project Map und Code widersprechen, gilt der **Code**.

---

## Projektstruktur

```text
stock-project/
├── AGENTS.md
├── README.md
├── compose.yaml
├── docker/
│   ├── prod.env.example
│   ├── python-full/
│   ├── web/
│   └── web-cli/
├── docs/
│   └── stock-project-project-map.md
├── models/                 # optionale lokale Modelle
├── repos/                  # optionale externe Repos wie Kronos / FinGPT
├── stock-system/           # Python-Logik: Intake, Pipeline, SEPA, EPA, Snapshot-Jobs
└── web/                    # Symfony-Webschicht + Doctrine-Migrationen
```

---

## Was das System aktuell macht

### Intake
Findet über starke Sektoren neue Kandidaten und schreibt sie in eine **kumulative Candidate Registry**.

### Buy-Side
Berechnet pro Instrument:

- Kronos
- Sentiment
- Merged Score
- SEPA / Minervini-Setup-Bewertung

Zusätzlich können historische Buy-Signale für **K / S / M** in eigene Snapshots geschrieben werden.

### Sell-Side
Berechnet pro Instrument:

- EPA / Exit- und Risk-Bewertung

### Historische Auswertung
Das System enthält inzwischen zwei getrennte historische Snapshot-Pfade:

1. **SEPA-Snapshots**
   - inkl. `forward_return_5d`
   - spätere Nachfüllung von `20d` / `60d`, sobald genug Zukunftsdaten vorhanden sind

2. **Buy-Signal-Snapshots**
   - historische Speicherung von `kronos_score`, `sentiment_score`, `merged_score`, `decision`
   - vorbereitete Forward-Return-Felder
   - Backfill-Job für Buy-Signal-Forward-Returns

### Preis-Historie
Das System pflegt eine lokale Preis-Historie in `instrument_price_history` auf Basis von `yfinance`:

- tägliche OHLCV-Daten
- idempotentes Upsert
- Basis für Forward-Return-Berechnung

### Web
Die Weboberfläche zeigt u. a.:

- Portfolio
- Watchlist
- Intake-Kandidaten
- Instrumente
- Buy Signal Matrix
- Sell Signal Matrix

---

## Minimal lauffähiger Modus

Der einfachste sinnvolle Modus ist:

- MariaDB / MySQL lokal oder im Docker-Stack
- Symfony-Webschicht
- Python
- `yfinance`
- Intake
- SEPA
- EPA

**Kronos** und **FinGPT** können optional sein, wenn zunächst nur der leichtere Modus genutzt werden soll.

---

## Docker-Quickstart

Der Root-Compose-Stack ist der aktuelle Standardweg.

### 1. Datenbank starten

```bash
docker compose up -d db
```

### 2. Migrationen ausführen

```bash
docker compose --profile setup run --rm migrate
```

### 3. Weboberfläche starten

```bash
docker compose up -d web
```

Dann öffnen:

```text
http://127.0.0.1:8000/
```

### 4. Python-Jobs über den `job`-Service ausführen

```bash
docker compose --profile jobs run --rm job python stock-system/scripts/run_watchlist_intake.py --mode=db
docker compose --profile jobs run --rm job python stock-system/scripts/run_pipeline.py --mode=db --source=all
docker compose --profile jobs run --rm job python stock-system/scripts/run_sepa.py --mode=db --source=all
docker compose --profile jobs run --rm job python stock-system/scripts/run_epa.py --mode=db --source=all
docker compose --profile jobs run --rm job python stock-system/scripts/run_buy_signal_snapshot.py
```

### Wichtiger Hinweis

Eine frische Docker-DB enthält nach den Migrationen noch **keine Instrumente**.  
`SEPA`, `EPA`, `pipeline` und `buy_signal_snapshot` brauchen aktive Instrumente. Lege sie entweder über die Weboberfläche an oder importiere bewusst eigene Daten.

Der Stack importiert absichtlich **keinen privaten Seed-Dump**.

### Lokale Assets für den vollständigen Pipeline-Job

Für Kronos-/Sentiment-nahe Vollpfade müssen lokale Assets vorhanden sein:

- `models/`
- `repos/Kronos`
- `repos/FinGPT`

---

## Produktiver Docker-Stack mit `prod.env`

Für den produktiven Compose-Pfad werden im Projekt typischerweise folgende Aufrufe verwendet:

```bash
docker compose --env-file docker/prod.env -p stock-project-prod --profile setup run --rm migrate
docker compose --env-file docker/prod.env -p stock-project-prod --profile jobs run --rm job python stock-system/scripts/run_watchlist_intake.py --mode=db
docker compose --env-file docker/prod.env -p stock-project-prod --profile jobs run --rm job python stock-system/scripts/run_pipeline.py --mode=db --source=all
docker compose --env-file docker/prod.env -p stock-project-prod --profile jobs run --rm job python stock-system/scripts/run_sepa.py --mode=db --source=all
docker compose --env-file docker/prod.env -p stock-project-prod --profile jobs run --rm job python stock-system/scripts/run_epa.py --mode=db --source=all
docker compose --env-file docker/prod.env -p stock-project-prod --profile jobs run --rm job python stock-system/scripts/run_buy_signal_snapshot.py
```

---

## Tägliche Jobs vs. Maintenance-Jobs

### Täglich sinnvoll

```bash
docker compose --env-file docker/prod.env -p stock-project-prod --profile jobs run --rm job python stock-system/scripts/run_pipeline.py --mode=db --source=all
docker compose --env-file docker/prod.env -p stock-project-prod --profile jobs run --rm job python stock-system/scripts/run_sepa.py --mode=db --source=all
docker compose --env-file docker/prod.env -p stock-project-prod --profile jobs run --rm job python stock-system/scripts/run_epa.py --mode=db --source=all
docker compose --env-file docker/prod.env -p stock-project-prod --profile jobs run --rm job python stock-system/scripts/run_buy_signal_snapshot.py
```

Optional zusätzlich:

```bash
docker compose --env-file docker/prod.env -p stock-project-prod --profile jobs run --rm job python stock-system/scripts/run_watchlist_intake.py --mode=db
```

### Nicht täglich, sondern als Maintenance / Backfill

```bash
docker compose --env-file docker/prod.env -p stock-project-prod --profile jobs run --rm job python stock-system/scripts/backfill_price_history.py
docker compose --env-file docker/prod.env -p stock-project-prod --profile jobs run --rm job python stock-system/scripts/backfill_sepa_forward_returns.py
docker compose --env-file docker/prod.env -p stock-project-prod --profile jobs run --rm job python stock-system/scripts/backfill_buy_signal_forward_returns.py
```

Diese drei Jobs sind **Nachhol- oder Maintenance-Pfade**, nicht der normale tägliche Kernlauf.

---

## Snapshot- und Backfill-Jobs

### Lokale Preis-Historie aufbauen

```bash
docker compose --env-file docker/prod.env -p stock-project-prod --profile jobs run --rm job python stock-system/scripts/backfill_price_history.py
```

Optionen:
- `--days`
- `--ticker`
- `--dry-run`

### Historische SEPA-Forward-Returns nachfüllen

```bash
docker compose --env-file docker/prod.env -p stock-project-prod --profile jobs run --rm job python stock-system/scripts/backfill_sepa_forward_returns.py
```

Optionen:
- `--dry-run`
- `--limit`
- `--json`

### Historische Buy-Signal-Snapshots (K / S / M) aufbauen

```bash
docker compose --env-file docker/prod.env -p stock-project-prod --profile jobs run --rm job python stock-system/scripts/run_buy_signal_snapshot.py
```

Optionen:
- `--dry-run`
- `--backfill-days`
- `--from-date`
- `--to-date`

### Historische Buy-Signal-Forward-Returns nachfüllen

```bash
docker compose --env-file docker/prod.env -p stock-project-prod --profile jobs run --rm job python stock-system/scripts/backfill_buy_signal_forward_returns.py
```

Optionen:
- `--dry-run`
- `--limit`
- `--json`

---

## Was aktuell historisch messbar ist

### SEPA
Messbar über:
- `instrument_sepa_snapshot`
- `forward_return_5d`
- perspektivisch `forward_return_20d` / `forward_return_60d`

### K / S / M
Messbar über:
- `instrument_buy_signal_snapshot`
- `kronos_score`
- `sentiment_score`
- `merged_score`
- `decision`
- perspektivisch ebenfalls `forward_return_5d/20d/60d`

### Was aktuell **nicht** existiert
Es gibt aktuell **keinen finalen Master-Score**, der

- Kronos
- Sentiment
- Merged Score
- SEPA

noch einmal zu einem einzigen „Super-Score“ zusammenfasst.

Die Systeme werden aktuell **nebeneinander** historisiert und können anschließend gegeneinander ausgewertet werden.

---

## Konfiguration

Das Projekt nutzt zentrale Pfadvariablen, damit keine harten lokalen Pfade im Code nötig sind.

### Wichtige Variablen

- `PROJECT_ROOT`
- `PYTHON_BIN`
- `MODELS_DIR`
- `KRONOS_DIR`
- `FINGPT_DIR`

### Typischer lokaler Ort

Diese Werte werden am besten in `web/.env.local` gesetzt.

### Beispiel

```dotenv
PROJECT_ROOT=C:/stock-project
PYTHON_BIN=C:/Python312/python.exe
MODELS_DIR=C:/stock-project/models
KRONOS_DIR=C:/stock-project/repos/Kronos
FINGPT_DIR=C:/stock-project/repos/FinGPT
```

Wenn `KRONOS_DIR` oder `FINGPT_DIR` nicht existieren und ein Job diese wirklich braucht, soll das System klar fehlschlagen statt still falsche Annahmen zu treffen.

---

## Voraussetzungen

### System

- Python 3.11+ oder 3.12
- PHP 8.2+
- Composer
- MariaDB oder MySQL
- Node.js / npm für Tailwind / Frontend-Build in `web/`

### Lokal vorhanden oder optional

- Git
- lokale Modell-Repos / Modell-Dateien
- optional XAMPP oder vergleichbare lokale PHP-/MariaDB-Umgebung für Nicht-Docker-Setups

---

## 5-Minuten-Setup ohne Docker

### 1. Repo klonen

```bash
git clone https://github.com/cblab/stock-project.git
cd stock-project
```

### 2. Python-Abhängigkeiten installieren

```bash
python -m venv .venv
.venv\Scripts\activate
pip install -r stock-system/requirements.txt
```

### 3. Web-Abhängigkeiten installieren

```bash
cd web
composer install
npm install
```

Optional:

```bash
npm run build
```

### 4. Datenbank konfigurieren

In `web/.env.local` z. B.:

```dotenv
DB_HOST=127.0.0.1
DB_PORT=3306
DB_NAME=stock_project
DB_USER=username
DB_PASSWORD=
DATABASE_URL="mysql://username:@127.0.0.1:3306/stock_project?serverVersion=10.4.32-MariaDB&charset=utf8mb4"
```

### 5. Migrationen ausführen

```bash
cd web
php bin/console doctrine:database:create --if-not-exists
php bin/console doctrine:migrations:migrate --no-interaction
```

### 6. Weboberfläche starten

```bash
cd web
php -S 127.0.0.1:8000 -t public
```

---

## Wichtige Webpfade

### Kernbereiche
- `/portfolio`
- `/watchlist`
- `/watchlist-intake`

### Signalansichten
- `/buy-signal-matrix`
- `/sell-signal-matrix`

### Instrumente
- `/instrument/{id}`
- `/instrument/{id}/edit`

---

## phpMyAdmin

Die Project Map behandelt phpMyAdmin als **optionale Admin-Oberfläche für DB-Inspektion**.  
Der aktuelle Root-Compose-Stack fokussiert standardmäßig auf:

- `db`
- `migrate`
- `web`
- `job`

Wenn ihr phpMyAdmin nutzen wollt, fügt es als separaten Compose-Service hinzu und verbindet es mit dem vorhandenen `db`-Service. Es ist kein Pflichtbestandteil des Kern-Workflows.

---

## Typische nächste Schritte nach frischem Setup

### Erst prüfen
- startet die Weboberfläche?
- funktionieren Migrationen?
- gibt es aktive Instrumente?
- laufen kleine Python-Jobs?

### Dann
- Intake laufen lassen
- Watchlist-Kandidaten erzeugen
- Hauptpipeline laufen lassen
- SEPA / EPA rechnen
- Buy-Snapshots historisieren
- Preis-Historie aufbauen
- später Forward-Returns backfillen

---

## Troubleshooting

### Web startet, aber Detailseiten sind leer
Oft fehlen:
- SEPA-Snapshots
- EPA-Snapshots
- oder ein Instrument wurde gerade erst angelegt und noch nicht nachgerechnet

Dann gezielt rechnen:

```bash
python stock-system/scripts/run_sepa.py --mode=db --source=all --tickers=DEIN_TICKER
python stock-system/scripts/run_epa.py --mode=db --source=all --tickers=DEIN_TICKER
```

### Python-Job startet nicht
Prüfen:
- `PYTHON_BIN`
- `PROJECT_ROOT`
- `KRONOS_DIR`
- `FINGPT_DIR`

### Docker-Job sieht neuen Code nicht
Wenn ein neues Script oder eine neue Migration gemergt wurde, aber der Container alten Code ausführt, das jeweilige Image neu bauen:

```bash
docker compose --profile jobs build --no-cache job
docker compose --profile setup build --no-cache migrate
```

Für den Prod-Stack entsprechend mit `--env-file docker/prod.env -p stock-project-prod`.

### DB-/FK-Probleme bei Migrationen
Bei MariaDB/MySQL müssen Foreign-Key-Spalten **exakt** Typ und Signedness der referenzierten Primärschlüssel treffen.

### Buy-Forward-Returns bleiben zunächst leer
Wenn `instrument_buy_signal_snapshot` erst wenige Tage historisch vorhanden ist, sind `forward_return_5d/20d/60d` zunächst erwartbar `NULL`. Das ist kein Bug, sondern fehlende Zukunftsdaten.

---

## Hinweise zur Architektur

### DB-first
Das System ist datenbankzentriert. Wichtige Tabellen/Objekte sind u. a.:

- `instrument`
- `pipeline_run`
- `pipeline_run_item`
- `instrument_sepa_snapshot`
- `instrument_epa_snapshot`
- `instrument_price_history`
- `instrument_buy_signal_snapshot`
- Candidate Registry

### Web ist Anzeige- und Orchestrierungsschicht
Symfony rendert DB-Zustand und startet definierte Prozesse, aber die eigentliche Analyse läuft in Python.

### Python ist Analyseschicht
Python berechnet:
- Intake
- Pipeline
- SEPA
- EPA
- Snapshot-Jobs
- Backfill-Jobs

---

## Dateien, die man zuerst lesen sollte

Für Änderungen an Teilbereichen sind typischerweise relevant:

- `compose.yaml`
- `AGENTS.md`
- `docs/stock-project-project-map.md`

Und subsystembezogen:

- `stock-system/scripts/`
- `stock-system/src/`
- `web/migrations/`
- `web/src/`

---

## Haftungsausschluss

Dieses Projekt ist Software für Research, Screening und Entscheidungsunterstützung.

Es ist:

- **keine Anlageberatung**
- **keine Aufforderung zum Kauf oder Verkauf von Wertpapieren**
- **kein Versprechen von Performance**

Nutzung erfolgt auf eigenes Risiko.  
Die Software wird unter MIT-Lizenz **ohne Gewährleistung** bereitgestellt.
