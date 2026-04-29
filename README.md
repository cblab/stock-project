# stock-project

Lokale Research- und Entscheidungsplattform für Aktienselektion und Trade-Evidence.

Das Projekt verbindet fünf Ebenen:

1. **Discovery / Intake**
   - sektorbasierte Kandidatenfindung
   - Candidate Registry
   - manuelle Übernahme in Watchlist oder Portfolio-Kontext

2. **Buy-Side Signals**
   - Kronos
   - Sentiment
   - Merged Score
   - **SEPA** = Specific Entry Point Analysis
   - Buy Signal Snapshots

3. **Sell-Side Signals**
   - **EPA** = Exit Point Analysis
   - Sell Signal Matrix

4. **Truth Layer**
   - Trade-Kampagnen
   - Trade-Events
   - Migration Seeds
   - PnL-Wahrheit

5. **Evidence Engine**
   - misst historische Entscheidungssituationen
   - trennt `eligible_full`, `eligible_outcome_only` und `excluded`
   - verhindert Hindsight Bias technisch über Snapshot-Provenance

Die Weboberfläche zeigt Portfolio, Watchlist, Intake, Instrument-Detailseiten sowie Buy- und Sell-Signalansichten.

---

## Zweck

`stock-project` ist **kein Trading-Bot**.

Es ist ein lokales System für:

- Research
- Scoring
- Watchlist- und Intake-Workflows
- Snapshotting historischer Signale
- Trade-Truth-Erfassung
- Evidence-Messung
- Entscheidungsunterstützung

Die eigentliche Analyse läuft überwiegend in Python.  
Symfony ist Anzeige-, Orchestrierungs- und Evidence-Service-Schicht.  
MariaDB ist der zentrale Persistenz-Layer.

---

## Was das System nicht ist

```text
keine Anlageberatung
keine Buy/Sell-Automation
keine Broker-Anbindung
kein autonomer Trader
kein LLM als numerischer Entscheider
kein Performance-Versprechen
```

Der Nutzer entscheidet final.

---

## Architektur in Kurzform

| Ebene | Stack | Rolle |
|---|---|---|
| Web UI | Symfony / PHP / Twig | Dashboards, Instrumente, Watchlist, Signalansichten |
| Evidence Services | Symfony / PHP / DBAL | Trade-Samples, Snapshot-Validation, Aggregation, Readout |
| Analytics Backend | Python | Intake, Pipeline, SEPA, EPA, Snapshot-Jobs, Backfills |
| Persistenz | MariaDB | Instrumente, Pipeline-Runs, Snapshots, Trade-Truth, Candidate Registry, Preis-Historie |
| Infrastruktur | Docker Compose | DB, Web, Migrationen, lokale Laufzeitumgebung |

Wichtige Trennung:

```text
web/           Symfony-Anwendung, Doctrine, Evidence Services, Tests
stock-system/  Python-Analyse und Job-Runtime
docs/          Projektkarte, Closure Audits, Zusatzdokumentation
docker/        Compose-Erweiterungen und Images
```

Kanonische Dokumentation:

```text
ROADMAP.md
ARCHITECTURE_v04.md
ARCHITECTURE_v05.md
docs/stock-project-project-map.md
docs/v05_evidence_engine_closure_audit.md
```

Wenn Dokumentation und Code widersprechen:

```text
aktueller Code > aktuelle Konfiguration > Logs > Projektkarte > Dokumentation > alte Notizen
```

---

## Projektstruktur

```text
stock-project/
├── AGENTS.md
├── README.md
├── ROADMAP.md
├── ARCHITECTURE_v04.md
├── ARCHITECTURE_v05.md
├── compose.yaml
├── docker/
├── docs/
│   ├── stock-project-project-map.md
│   └── v05_evidence_engine_closure_audit.md
├── models/                 # optionale lokale Modelle
├── repos/                  # optionale externe Repos wie Kronos / FinGPT
├── stock-system/           # Python-Logik: Intake, Pipeline, SEPA, EPA, Snapshot-Jobs
├── tools/                  # lokale Hilfswerkzeuge, Agent Exec Proxy
└── web/                    # Symfony-Webschicht + Doctrine + Evidence Services
```

---

## Aktueller Entwicklungsstand

```text
v0.4 Truth Layer:          abgeschlossen
v0.5 Evidence Engine Lite: abgeschlossen
v0.6 Signal Evidence:      nächster Schritt
```

### v0.4 Truth Layer

Kernobjekte:

```text
trade_campaign
trade_event
trade_migration_log
TradeSnapshotResolver
TradeStateMachine
TradeEventValidator
TradePnlCalculator
TradeEventWriter
TradeEventWriteResult
```

21 bestehende Positionen wurden als `migration_seed` migriert. Snapshot-IDs wurden bei Hindsight-Risiko bewusst `NULL` gelassen.

### v0.5 Evidence Engine Lite

v0.5 ist abgeschlossen.

Die Engine kann:

```text
TradeOutcomeSamples aus dem Truth Layer lesen
Samples nach eligible_full / eligible_outcome_only / excluded klassifizieren
Entry- und Exit-Kontexte aggregieren
Confidence berechnen
Readouts mit maschinenlesbaren Warning-Codes erzeugen
DB-level SnapshotValidation anwenden
Hindsight-Bias über available_at/source_run_id/pipeline_run prüfen
```

Wichtigste Regel:

```text
eligible_full entsteht nur bei DB-validierten Entry-Snapshots.
```

Nicht validierte, alte oder seed-basierte Samples bleiben konservativ `eligible_outcome_only` oder `excluded`.

---

## Evidence Engine: Kernmechanik

Ein Trade-Sample besteht aus:

```text
Signalzustand zum Entry + späteres Outcome
```

Die Engine aggregiert nicht blind einzelne Trades, sondern Klassen von Entscheidungssituationen.

Beispiel für spätere v0.6-Buckets:

```text
SEPA >= 75
EPA >= 70
BuySignal decision = ENTRY
```

v0.5 liefert dafür die sichere Grundlage:

```text
eligible_full
  Snapshot war zum Entry-Zeitpunkt nachweislich verfügbar.

eligible_outcome_only
  Outcome ist gültig, aber Entry-Snapshot-Kontext nicht voll validiert.

excluded
  Sample ist nicht aggregierbar.
```

---

## Anti-Hindsight-Regeln

Ein Snapshot kann nur `eligible_full` stützen, wenn:

```text
Snapshot existiert
instrument_id matcht
source_run_id vorhanden
available_at vorhanden
available_at <= entry timestamp
pipeline_run existiert
pipeline_run.status = success
pipeline_run.exit_code = 0
pipeline_run.finished_at vorhanden
available_at >= pipeline_run.finished_at
```

Wenn das nicht beweisbar ist:

```text
kein eligible_full
```

---

## Was das System aktuell macht

### Intake

Findet über starke Sektoren neue Kandidaten und schreibt sie in eine kumulative Candidate Registry.

### Buy-Side

Berechnet pro Instrument:

- Kronos
- Sentiment
- Merged Score
- SEPA / Minervini-Setup-Bewertung
- Buy-Signal-Snapshots

### Sell-Side

Berechnet pro Instrument:

- EPA / Exit- und Risk-Bewertung

### Trade Truth

Erfasst und validiert:

- Entries
- Trims
- Hard Exits
- Return to Watchlist
- Migration Seeds
- PnL-Werte

### Evidence

Misst:

- Trade Outcome Evidence
- Entry-/Exit-Kontext-Aggregationen
- Anteil full vs outcome-only Evidence
- Confidence anhand von `n` und Streuung
- Datenqualitäts-Warnings

---

## Historische Snapshots

Snapshot-Tabellen:

```text
instrument_buy_signal_snapshot
instrument_sepa_snapshot
instrument_epa_snapshot
```

v0.5-Provenance-Felder:

```text
source_run_id → pipeline_run.id
available_at → Zeitpunkt, ab dem der Snapshot anti-hindsight-verwendbar ist
```

Finalisierte Snapshot-Zeilen sind immutable:

```text
Business-Felder bleiben stabil.
source_run_id bleibt stabil.
available_at bleibt stabil.
updated_at bleibt stabil.
```

Unfinalisierte Snapshot-Zeilen bleiben reparierbar.

---

## Docker-Quickstart

Der Root-Compose-Stack ist der Standardweg.

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

### 4. Python-Jobs ausführen

Je nach Compose-Profil/Stack kann ein `job`-Service verfügbar sein. Typische Jobs:

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

Backfills sind nicht täglicher Kernlauf.

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

Wichtige Variablen:

```text
PROJECT_ROOT
PYTHON_BIN
MODELS_DIR
KRONOS_DIR
FINGPT_DIR
DATABASE_URL
DB_HOST
DB_PORT
DB_NAME
DB_USER
DB_PASSWORD
```

Typischer lokaler Ort:

```text
web/.env.local
```

Beispiel:

```dotenv
PROJECT_ROOT=C:/stock-project
PYTHON_BIN=C:/Python312/python.exe
MODELS_DIR=C:/stock-project/models
KRONOS_DIR=C:/stock-project/repos/Kronos
FINGPT_DIR=C:/stock-project/repos/FinGPT
```

---

## Voraussetzungen

- Python 3.11+ oder 3.12
- PHP 8.2+
- Composer
- MariaDB oder MySQL
- Node.js / npm für Tailwind / Frontend-Build in `web/`
- Docker für den Standard-Stack

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

```text
PYTHON_BIN
PROJECT_ROOT
KRONOS_DIR
FINGPT_DIR
DATABASE_URL / DB_* Variablen
```

### Docker-Job sieht neuen Code nicht

Wenn ein neues Script oder eine neue Migration gemergt wurde, aber der Container alten Code ausführt:

```bash
docker compose build --no-cache web
docker compose up -d --build
```

Für Profile entsprechend `--profile jobs` oder `--profile setup` ergänzen, falls vorhanden.

### DB-/FK-Probleme bei Tests oder Migrationen

Bei MariaDB/MySQL müssen Foreign-Key-Spalten exakt Typ und Signedness der referenzierten Primärschlüssel treffen. Integrationstest-Fixtures müssen Pflichtfelder und FK-Zeilen explizit anlegen.

---

## Dateien, die man zuerst lesen sollte

```text
ROADMAP.md
ARCHITECTURE_v04.md
ARCHITECTURE_v05.md
docs/stock-project-project-map.md
docs/v05_evidence_engine_closure_audit.md
AGENTS.md
compose.yaml
```

Subsystembezogen:

```text
stock-system/scripts/
stock-system/src/
stock-system/tests/
web/migrations/
web/src/
web/tests/
```

---

## Haftungsausschluss

Dieses Projekt ist Software für Research, Screening und Entscheidungsunterstützung.

Es ist:

- **keine Anlageberatung**
- **keine Aufforderung zum Kauf oder Verkauf von Wertpapieren**
- **kein Versprechen von Performance**

Nutzung erfolgt auf eigenes Risiko. Die Software wird unter MIT-Lizenz ohne Gewährleistung bereitgestellt.
