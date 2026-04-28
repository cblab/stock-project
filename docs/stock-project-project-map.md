# stock-project-project-map.md

Stand: 2026-04-27  
Status: kanonische Projektkarte für Navigation, Architektur- und Agentenarbeit

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
  Python-/Pipeline-Komponenten, Scoring-, Intake-, Reporting- und Datenlogik

docker/
  Compose-Erweiterungen, Images, Laufzeitkonfiguration

tools/
  lokale Hilfswerkzeuge, u. a. Agent Exec Proxy

docs/
  zusätzliche Dokumentation

AGENTS.md
  verbindliche Arbeitsregeln für Coding-Agenten

ROADMAP.md
  Versions- und Chunk-Planung

ARCHITECTURE_v04.md
  finaler v0.4 Truth-Layer-Architekturstand

ARCHITECTURE_v05.md
  v0.5 Evidence Engine Lite Architekturvertrag
```

---

## Laufzeitrealität

Docker Compose ist die lokale Orchestrierung.

Typische Dienste:

```text
db
web
phpmyadmin
agent-runner
```

Wichtige Ports:

```text
Prod App:        http://127.0.0.1:8000
Dev App:         http://127.0.0.1:8001
Prod phpMyAdmin: http://127.0.0.1:8081
Dev phpMyAdmin:  http://127.0.0.1:8082
Agent Proxy:     http://localhost:8787
```

Der Agent Exec Proxy läuft im `agent-runner`-Kontext und stellt sichere, eng begrenzte Check-Endpunkte bereit.

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

`/run/php-lint` braucht JSON:

```json
{
  "file": "src/Service/Evidence/TradeOutcomeExtractor.php"
}
```

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
```

Host-Schritte werden vom Nutzer ausgeführt.

Agenten dürfen Host-Befehle exakt nennen, aber nicht behaupten, sie selbst ausgeführt zu haben.

---

## Pflichtablauf für Arbeitsaufträge

1. Projektkarte/GitNexus für Lagebild nutzen.
2. Relevante Dateien gezielt prüfen.
3. Bestehende Patterns erkennen.
4. Minimalen Diff erzeugen.
5. Checks über Agent Exec Proxy durchführen.
6. Prüflücken klar melden.

Keine breiten Scans. Keine Scope-Ausweitung ohne Grund.

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

v0.5 Evidence Engine Lite ist aktiv.

Ziel:

```text
read-only Evidence aus:
1. Trade Outcome Evidence
2. Signal Forward-Return Evidence
```

C1 ist abgeschlossen:

```text
Evidence Read Models
EvidenceTradeSample
EvidenceSignalSample
EvidenceMetricSummary
EvidenceSource
SignalSource
SignalFamily
EvidenceEligibilityStatus
EvidenceExclusionReason
EvidenceDataQualityFlag
EvidenceConfidenceLevel
```

Aktiver nächster Chunk:

```text
C2 Closed Trade Outcome Extractor
```

Danach parallel möglich:

```text
C6 Confidence Calculator
C8 Validation Fixtures / Poison Pills
```

Kanonische Datei:

```text
ARCHITECTURE_v05.md
```

---

## v0.5 zentrale Regeln

```text
Evidence Engine ist read-only.
Keine Write-Operationen auf trade_campaign/trade_event.
Trade Evidence und Signal Evidence nicht vermischen.
live/paper/pseudo nicht still vermischen.
migration_seed/manual_seed markieren.
Anti-Hindsight früh prüfen.
Keine Wahrscheinlichkeit ohne n und Confidence.
```

Signalquellen:

```text
sepa
epa
buy_signal
kronos
sentiment
custom
```

Die Evidence Engine ist quellenagnostisch.

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
```

Wichtige v0.4 Tabellen:

```text
trade_campaign
trade_event
trade_migration_log
instrument
instrument_buy_signal_snapshot
instrument_sepa_snapshot
instrument_epa_snapshot
```

---

## Relevante Dateien für aktuelle v0.5-Arbeit

C1:

```text
web/src/Service/Evidence/Model/
```

C2:

```text
web/src/Service/Evidence/TradeOutcomeExtractor.php
web/tests/Service/Evidence/TradeOutcomeExtractorIntegrationTest.php
web/migrations/Version20260426043300.php
web/migrations/Version20260419162000.php
```

C6:

```text
web/src/Service/Evidence/Model/EvidenceConfidenceLevel.php
web/src/Service/Evidence/Model/EvidenceMetricSummary.php
```

C8:

```text
web/tests/Service/Evidence/
web/tests/Service/Trade/
```

---

## Dokumentationsrollen

```text
ROADMAP.md
  Was wird wann gebaut?

ARCHITECTURE_v04.md
  Warum und wie ist der Truth Layer gebaut?

ARCHITECTURE_v05.md
  Warum und wie wird Evidence Engine Lite gebaut?

stock-project-project-map.md
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
Proxy-Checks vorhanden?
lint-container grün?
Keine heimliche UI/Migration/Aggregation?
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

Beispiele:

```powershell
curl http://localhost:8787/health

docker compose --env-file docker/prod.env -p stock-project-prod restart agent-runner

docker compose --env-file docker/prod.env -p stock-project-dev -f compose.yaml -f docker/compose.dev.yml exec web sh -lc "cd /app/web && php bin/phpunit tests/Service/Evidence/TradeOutcomeExtractorIntegrationTest.php"
```

Agenten führen diese Host-Befehle nicht selbst aus.

---

## Kurzstatus

```text
v0.4 abgeschlossen
v0.5 C1 gemerged
v0.5 C2 aktiv
C6/C8 danach parallel
Agent Exec Proxy als Check-Gate verbindlich
```
