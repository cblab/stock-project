# stock-project

Research- und Entscheidungsplattform für Aktienselektion mit drei Ebenen:

1. **Discovery / Intake**
   - sektorbasierte Kandidatenfindung
   - Candidate Registry
   - manuelle Übernahme in die Watchlist

2. **Buy-Side**
   - Kronos
   - Sentiment
   - Merged Score
   - **SEPA** = *Specific Entry Point Analysis*
   - Buy Signal Matrix

3. **Sell-Side**
   - **EPA** = *Exit Point Analysis*
   - Sell Signal Matrix

Die Weboberfläche zeigt Portfolio, Watchlist, Intake, Instrument-Detailseiten sowie Buy- und Sell-Signalansichten.

---

## Projektstruktur

~~~text
stock-project/
├── stock-system/   # Python-Logik: Intake, Pipeline, SEPA, EPA
├── web/            # Symfony-Webschicht
├── models/         # optionale lokale Modelle
├── repos/          # optionale externe Repos wie Kronos / FinGPT
└── .env / .env.local / lokale Runtime-Konfiguration
~~~

---

## Was das System macht

### Intake

Findet über starke Sektoren neue Kandidaten und schreibt sie in eine **kumulative Candidate Registry**.

### Buy

Berechnet pro Instrument:
- Kronos
- Sentiment
- Merged Score
- SEPA / Minervini Setup-Bewertung

### Sell

Berechnet pro Instrument:
- EPA / Exit & Risk Bewertung

### Web

Zeigt:
- Portfolio
- Watchlist
- Intake-Kandidaten
- Instrumente
- Buy Signal Matrix
- Sell Signal Matrix

---

## Minimal lauffähiger Modus

Der einfachste sinnvolle Modus ist:

- MariaDB / MySQL lokal
- Symfony-Webschicht
- Python
- `yfinance`
- SEPA
- EPA
- Intake

**Kronos** und **FinGPT** können optional sein, wenn zunächst nur der leichtere Modus genutzt werden soll.

---

## Docker-Quickstart

Der Docker-Branch stellt einen reproduzierbaren lokalen Runtime-Pfad bereit:

~~~bash
docker compose up -d db
docker compose --profile setup run --rm migrate
docker compose --profile jobs run --rm intake
docker compose --profile jobs run --rm sepa
docker compose --profile jobs run --rm epa
docker compose --profile jobs run --rm pipeline
~~~

Die optionale Weboberfläche startet mit:

~~~bash
docker compose up -d web
~~~

Dann öffnen:

~~~text
http://127.0.0.1:8000/
~~~

Eine frische Docker-DB enthält nach den Migrationen noch keine Instrumente.
`SEPA`, `EPA` und `pipeline` brauchen mindestens ein aktives Instrument; lege es
entweder über `/instrument/new` in der Weboberfläche an oder importiere bewusst
eigene Daten. Der Branch importiert absichtlich keinen privaten Seed-Dump.

Für den vollständigen Pipeline-Job müssen lokale Assets vorhanden sein:

- `models/`
- `repos/Kronos`
- `repos/FinGPT`

Details zu Profilen, Mounts, leerer DB und JSON-Output stehen in
[`docs/docker-quickstart.md`](docs/docker-quickstart.md).

---

## Voraussetzungen

### System

- Python 3.11+ oder 3.12
- PHP 8.2+
- Composer
- MariaDB oder MySQL
- Node.js / npm für Tailwind / Frontend-Build in `web/`

### Lokal vorhanden oder optional

- XAMPP oder vergleichbare lokale PHP-/MariaDB-Umgebung
- Git

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

~~~dotenv
PROJECT_ROOT=C:/stock-project
PYTHON_BIN=C:/Python312/python.exe
MODELS_DIR=C:/stock-project/models
KRONOS_DIR=C:/stock-project/repos/Kronos
FINGPT_DIR=C:/stock-project/repos/FinGPT
~~~

### Hinweis

Wenn `KRONOS_DIR` oder `FINGPT_DIR` nicht existieren und ein Job diese wirklich braucht, soll das System klar fehlschlagen statt still falsche Annahmen zu treffen.

---

## 5-Minuten-Setup

### 1. Repo klonen

~~~bash
git clone https://github.com/cblab/stock-project.git
cd stock-project
~~~

### 2. Python-Abhängigkeiten installieren

~~~bash
python -m venv .venv
.venv\Scripts\activate
pip install -r stock-system/requirements.txt
~~~

Wenn lokal mit `.deps` gearbeitet wird, kann die bestehende Projektkonvention weiterverwendet werden.

### 3. Web-Abhängigkeiten installieren

~~~bash
cd web
composer install
npm install
~~~

Wenn Tailwind / Assets gebraucht werden:

~~~bash
npm run build
~~~

oder die projektinterne Symfony-/Tailwind-Variante, je nach aktuellem Setup.

### 4. Datenbank konfigurieren

In `web/.env.local` z. B.:

~~~dotenv
DB_HOST=127.0.0.1
DB_PORT=3306
DB_NAME=stock_project
DB_USER=username
DB_PASSWORD=
DATABASE_URL="mysql://username:@127.0.0.1:3306/stock_project?serverVersion=10.4.32-MariaDB&charset=utf8mb4"
~~~

### 5. Migrationen ausführen

~~~bash
cd web
php bin/console doctrine:database:create --if-not-exists
php bin/console doctrine:migrations:migrate --no-interaction
~~~

### 6. Weboberfläche starten

~~~bash
cd web
php -S 127.0.0.1:8000 -t public
~~~

Dann öffnen:

~~~text
http://127.0.0.1:8000/
~~~

---

## Standard-Workflow

### 1. Intake

Neue Kandidaten finden und in die Candidate Registry schreiben:

~~~bash
python stock-system/scripts/run_watchlist_intake.py --mode=db
~~~

### 2. Hauptpipeline: Kronos + Sentiment + Merged Decision

~~~bash
python stock-system/scripts/run_pipeline.py --mode=db --source=portfolio
python stock-system/scripts/run_pipeline.py --mode=db --source=watchlist
python stock-system/scripts/run_pipeline.py --mode=db --source=all
~~~

### 3. SEPA / Buy-/Setup-Layer

~~~bash
python stock-system/scripts/run_sepa.py --mode=db --source=portfolio
python stock-system/scripts/run_sepa.py --mode=db --source=watchlist
python stock-system/scripts/run_sepa.py --mode=db --source=all
~~~

### 4. EPA / Exit & Risk Sell-/Risk-Layer

~~~bash
python stock-system/scripts/run_epa.py --mode=db --source=portfolio
python stock-system/scripts/run_epa.py --mode=db --source=watchlist
python stock-system/scripts/run_epa.py --mode=db --source=all
~~~

### 5. SEPA nur für bestimmte Ticker

~~~bash
python stock-system/scripts/run_sepa.py --mode=db --source=all --tickers=AAPL
~~~

### 6. EPA nur für bestimmte Ticker

~~~bash
python stock-system/scripts/run_epa.py --mode=db --source=all --tickers=AAPL
~~~

### 7. SEPA / EPA mit Zeitraum und Intervall

~~~bash
python stock-system/scripts/run_sepa.py --mode=db --source=all --period=18mo --interval=1d
python stock-system/scripts/run_epa.py  --mode=db --source=all --period=18mo --interval=1d
~~~

### 8. Ruhiger Standardlauf

~~~bash
python stock-system/scripts/run_pipeline.py --mode=db --source=all --quiet
python stock-system/scripts/run_sepa.py --mode=db --source=all --quiet
python stock-system/scripts/run_epa.py  --mode=db --source=all --quiet
~~~

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

## Optional fehlende Spezialteile nach frischem Clone

Ein frischer Clone kann ohne einige schwere Spezialteile starten, aber nicht alle Signale sind dann voll nutzbar.

### Typisch optional / extern

- Kronos-Repo
- FinGPT-Repo
- lokale Modelle unter `models/`

### Wenn diese fehlen

Dann kann je nach Job gelten:
- Intake / SEPA / EPA laufen teilweise trotzdem
- Kronos-/Sentiment-spezifische Pfade sind eingeschränkt
- Web-Launcher oder Python-Jobs sollen klare Fehlermeldungen geben

---

## Empfohlener Modus

- DB lokal
- Symfony-Webschicht
- Intake
- SEPA
- EPA
- `yfinance`
- Kronos / FinGPT

---

## Typische nächste Schritte nach frischem Setup

### Erst prüfen

- startet die Weboberfläche?
- funktionieren Migrationen?
- lädt Portfolio / Watchlist / Intake?
- funktionieren kleine Python-Jobs?

### Dann

- Intake laufen lassen
- Watchlist-Kandidaten erzeugen
- einzelne Ticker per `run_sepa.py` / `run_epa.py` rechnen
- Buy / Sell Signal Matrix prüfen

---

## Hinweise zur Architektur

### DB-first

Das System ist datenbankzentriert:
- Instrumente
- Pipeline-Runs
- SEPA-Snapshots
- EPA-Snapshots
- Candidate Registry

### Web ist Anzeige- und Orchestrierungsschicht

Symfony zeigt und startet Prozesse, aber die eigentliche Analyse läuft in Python.

### Python ist Analyseschicht

Python berechnet:
- Intake
- Pipeline
- SEPA
- EPA

---

## Troubleshooting

### Web startet, aber Detailseiten sind leer

Oft fehlen:
- SEPA-Snapshots
- EPA-Snapshots
- oder ein Kandidat wurde gerade erst in die Watchlist übernommen und noch nicht nachgerechnet

Dann gezielt rechnen:

~~~bash
python stock-system/scripts/run_sepa.py --mode=db --source=all --tickers=DEIN_TICKER
python stock-system/scripts/run_epa.py --mode=db --source=all --tickers=DEIN_TICKER
~~~

### Python-Job startet nicht

Prüfen:
- `PYTHON_BIN`
- `PROJECT_ROOT`
- `KRONOS_DIR`
- `FINGPT_DIR`

### DB-Probleme

Prüfen:
- `DATABASE_URL`
- Migrationen
- ob die lokale MariaDB-Version zur Konfiguration passt

---

## Haftungsausschluss

Dieses Projekt ist Software für Research, Screening und Entscheidungsunterstützung.

Es ist:
- **keine Anlageberatung**
- **keine Aufforderung zum Kauf oder Verkauf von Wertpapieren**
- **kein Versprechen von Performance**

Nutzung erfolgt auf eigenes Risiko. Die Software wird unter MIT-Lizenz **ohne Gewährleistung** bereitgestellt.
