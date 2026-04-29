# stock-project-project-map.md

Stand: 2026-04-29  
Status: kanonische Projektkarte für Navigation, Architektur- und Agentenarbeit nach Abschluss von v0.5

## Zweck

Diese Datei ist der Navigationsanker für das Repository. Sie erklärt, wo relevante Teile liegen, welche Laufzeitrealität gilt und welche Arbeitsregeln für Agenten verbindlich sind.

Sie ersetzt keine Codeprüfung. Wenn diese Datei und der Code widersprechen:

```text
aktueller Code > aktuelle Konfiguration > Logs > Projektkarte > Dokumentation > alte Notizen > Annahmen
```

---

## Projektziel

stock-project ist ein lokales Investment-/Trading-Decision-System.

Nicht-Ziele:

```text
kein autonomer Trader
keine Broker-Anbindung
keine automatische Live-Gewichtsänderung
kein LLM als numerischer Entscheider
keine Anlageberatung-Automation
```

Der Nutzer entscheidet final.

---

## Hauptbereiche

```text
web/
  Symfony Web-App, UI, Controller, Services, Doctrine, Twig, Tests

stock-system/
  Python-/Pipeline-Komponenten, Scoring-, Intake-, Reporting- und Snapshot-Logik

docker/
  Compose-Erweiterungen, Images, Laufzeitkonfiguration

tools/
  lokale Hilfswerkzeuge, u. a. Agent Exec Proxy

docs/
  zusätzliche Dokumentation, Projektkarte, Closure Audits

AGENTS.md
  verbindliche Arbeitsregeln für Coding-Agenten

ROADMAP.md
  Versions- und Chunk-Planung

ARCHITECTURE_v04.md
  finaler v0.4 Truth-Layer-Architekturstand

ARCHITECTURE_v05.md
  finaler v0.5 Evidence-Engine-Architekturstand

docs/v05_evidence_engine_closure_audit.md
  Abschlussaudit für v0.5
```

---

## Laufzeitrealität

Docker Compose ist die lokale Orchestrierung.

Typische Dienste je nach Stack/Override:

```text
db
web
phpmyadmin
agent-runner
optional job/migrate profiles
```

Wichtige lokale Ports in der üblichen Dev/Prod-Konfiguration:

```text
Prod App:         http://127.0.0.1:8000
Dev App:          http://127.0.0.1:8001
Prod phpMyAdmin:  http://127.0.0.1:8081
Dev phpMyAdmin:   http://127.0.0.1:8082
Agent Proxy:      http://localhost:8787
```

Achtung:

```text
Die DB muss nicht zwingend auf 127.0.0.1:3306 exposed sein.
Wenn MariaDB nur im Docker-Netzwerk erreichbar ist, nutzen Container den Hostnamen db.
```

---

## Agent Exec Proxy

Host URL:

```text
http://localhost:8787
```

Container-/OpenClaw-URL:

```text
http://host.docker.internal:8787
```

Wichtig:

```text
Aus Container-Kontext nicht localhost verwenden.
Wenn /health nicht erreichbar ist: Blocker melden.
Lokale Checks sind kein gleichwertiger Ersatz für Proxy-Checks.
```

Erlaubte Endpunkte:

```text
GET  /health
POST /run/git-status
POST /run/git-diff
POST /run/composer-validate
POST /run/php-lint
POST /run/lint-container
POST /run/composer-dump-autoload
```

Agenten führen keine freien Docker-Kommandos aus, wenn sie im Containerkontext laufen. Host-Schritte führt der Nutzer aus.

---

## Agentengrenzen

Ein Agent im Container kann nicht voraussetzen:

```text
Docker-Binary
Docker-Daemon
Docker-Socket
freie Shell
direkte DB-Mutation
Container-Neustart
Migration Run
Runtime Package Installation
```

Host-Schritte werden exakt genannt, aber nicht als vom Agenten ausgeführt behauptet.

---

## Pflichtablauf für Arbeitsaufträge

1. Projektkarte/GitNexus für Lagebild nutzen.
2. Wenn GitNexus stale/defekt ist: direkte Dateiprüfung nutzen und Tooling-Risiko melden.
3. Relevante Dateien gezielt prüfen.
4. Bestehende Patterns übernehmen.
5. Minimalen Diff erzeugen.
6. Checks über Agent Exec Proxy oder explizit genannten Host-Runner durchführen.
7. Prüflücken klar melden.

Keine breiten Scans. Keine Scope-Ausweitung ohne Gate.

---

## v0.4 Status

v0.4 Truth Layer ist abgeschlossen.

Kernbestandteile:

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

Finale Regeln:

```text
realized_pnl_pct ist Ratio
0.30 = 30 %

hard_exit setzt instrument.is_portfolio = 0, active = 1
return_to_watchlist setzt instrument.is_portfolio = 0, active = 1
trim lässt instrument.is_portfolio = 1
```

Legacy Seed:

```text
21 bestehende Positionen wurden als migration_seed migriert.
Snapshot-IDs wurden bei Hindsight-Risiko bewusst NULL gelassen.
```

Kanonische Datei:

```text
ARCHITECTURE_v04.md
```

---

## v0.5 Status

v0.5 Evidence Engine Lite ist abgeschlossen.

Kanonische Dateien:

```text
ARCHITECTURE_v05.md
docs/v05_evidence_engine_closure_audit.md
```

Kernleistung:

```text
Trade-Samples aus Truth Layer lesen
Eligibility klassifizieren
Entry-/Exit-Kontexte aggregieren
Confidence berechnen
Readout mit Warning-Codes erzeugen
SnapshotValidationService für eligible_full nutzen
Writer-Provenance und Immutability absichern
```

Evidenzklassen:

```text
eligible_full
eligible_outcome_only
excluded
```

Zentrale Regel:

```text
eligible_full nur mit DB-validierten Snapshots.
Wenn unsicher: eligible_outcome_only oder excluded.
```

C10-Abschluss:

```text
C10a Writer Provenance + Immutability   done
C10b SnapshotValidationService          done
C10c Eligibility Integration            done
C10d Writer-Immutability Tests          done
```

---

## v0.5 zentrale Regeln

```text
Evidence Engine ist read-only.
Keine Write-Operationen auf trade_campaign/trade_event.
Trade Evidence und Signal Evidence nicht vermischen.
live/paper/pseudo nicht still vermischen.
migration_seed/manual_seed markieren.
Anti-Hindsight DB-level prüfen.
Keine Wahrscheinlichkeit ohne n und Confidence.
Keine Recommendation-Semantik.
```

Anti-Hindsight-Invarianten:

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

---

## v0.6 Status

v0.6 ist der nächste aktive Entwicklungsabschnitt.

Ziel:

```text
Signal Evidence Layer auf voll validierten Entry-Kontexten.
```

Regel:

```text
Nur eligible_full darf Eingang für signalbasierte Entry-Evidence sein.
eligible_outcome_only darf nicht stillschweigend als Signal-Evidence verwendet werden.
```

Mögliche erste Buckets:

```text
SEPA >= 75
EPA >= 70
BuySignal decision = ENTRY/WATCH
kombinierte Signalprofile
```

Nicht-Ziele:

```text
keine Buy/Sell-UI
keine Recommendation Engine
keine automatische Trades
keine Forward-Return-Leakage
```

---

## Schema-first Testregel

Vor Integrationstest-Fixtures:

```text
Migrationen prüfen.
Pflichtfelder prüfen.
FK-Kanten prüfen.
ENUM-Werte prüfen.
Keine nicht existierenden Spalten verwenden.
Keine Fake-FK-IDs ohne referenzierte Zeilen.
Keine Tests gegen stock_project, wenn stock_project_test gemeint ist.
```

Wichtige Tabellen:

```text
instrument
pipeline_run
pipeline_run_item
trade_campaign
trade_event
trade_migration_log
instrument_buy_signal_snapshot
instrument_sepa_snapshot
instrument_epa_snapshot
```

---

## Relevante Dateien für v0.5/v0.6-Arbeit

Evidence Core:

```text
web/src/Service/Evidence/EvidenceEligibilityEvaluator.php
web/src/Service/Evidence/SnapshotValidationService.php
web/src/Service/Evidence/TradeOutcomeExtractor.php
web/src/Service/Evidence/EntryEvidenceAggregator.php
web/src/Service/Evidence/ExitEvidenceAggregator.php
web/src/Service/Evidence/EvidenceConfidenceCalculator.php
web/src/Service/Evidence/EvidenceReadoutBuilder.php
web/src/Service/Evidence/Model/
```

Trade Truth:

```text
web/src/Service/Trade/TradeEventWriter.php
web/src/Service/Trade/TradeEventValidator.php
web/src/Service/Trade/TradeStateMachine.php
```

Snapshot Writer:

```text
stock-system/src/sepa/persistence.py
stock-system/src/epa/persistence.py
stock-system/src/db/buy_signal_snapshot.py
```

Tests:

```text
web/tests/Service/Evidence/
stock-system/tests/test_sepa_snapshot_writer.py
stock-system/tests/test_epa_snapshot_writer.py
stock-system/tests/test_buy_signal_snapshot_writer.py
stock-system/tests/test_sepa_snapshot_integration.py
stock-system/tests/test_epa_snapshot_integration.py
stock-system/tests/test_buy_signal_snapshot_integration.py
```

---

## Dokumentationsrollen

```text
ROADMAP.md
  Was wird wann gebaut?

ARCHITECTURE_v04.md
  Warum und wie ist der Truth Layer gebaut?

ARCHITECTURE_v05.md
  Warum und wie ist Evidence Engine Lite gebaut und abgeschlossen?

docs/v05_evidence_engine_closure_audit.md
  Abschlussprüfung für v0.5

docs/stock-project-project-map.md
  Wo liegt was, wie arbeitet ein Agent sicher im Repo?

AGENTS.md
  Verbindliche Arbeitsregeln für Coding-Agenten
```

Keine neue Roadmap-Datei anlegen, solange `ROADMAP.md` existiert.

---

## Review-Gates

Für PRs:

```text
Scope eingehalten?
Read-only eingehalten?
DB-Schema korrekt?
FKs korrekt?
ENUMs korrekt?
Tests gegen reales Schema?
Proxy-Checks vorhanden oder Host-Prüflücke sauber gemeldet?
lint-container grün, falls Code betroffen?
Keine heimliche UI/Migration/Aggregation?
Keine Recommendation-Semantik?
```

PR-Urteile:

```text
APPROVE
REQUEST CHANGES
COMMENT
```

---

## Host-Schritte

Wenn Runtime-Verifikation nötig ist, exakte Host-Befehle nennen.

Für cmder/cmd bevorzugt:

```cmd
cd /d E:\stock-project
```

Beispiel Health:

```cmd
curl http://localhost:8787/health
```

Beispiel Symfony-Test im Container:

```cmd
docker compose --env-file docker/prod.env -p stock-project-dev -f compose.yaml -f docker/compose.dev.yml exec web sh -lc "cd /app/web && php bin/phpunit tests/Service/Evidence/EvidenceReadoutBuilderTest.php"
```

Beispiel Python-Integrationstest im Docker-Netzwerk, falls DB nicht auf Host-Port exposed ist:

```cmd
docker run --rm --network stock-project-dev_default -v E:\stock-project:/work -w /work -e "DATABASE_URL=mysql://USER:PASS@db:3306/stock_project_test?charset=utf8mb4" python:3.12-slim sh -lc "pip install -q pytest pymysql && python -m pytest stock-system/tests/test_sepa_snapshot_integration.py stock-system/tests/test_epa_snapshot_integration.py stock-system/tests/test_buy_signal_snapshot_integration.py -q"
```

Agenten führen diese Host-Befehle nicht selbst aus.

---

## Kurzstatus

```text
v0.4 abgeschlossen
v0.5 abgeschlossen
v0.6 Signal Evidence Layer als nächster Schritt
Agent Exec Proxy bleibt Check-Gate
```
