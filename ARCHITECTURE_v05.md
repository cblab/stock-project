# ARCHITECTURE v0.5 – Evidence Engine Lite

stock-project · v0.5 · Architekturvertrag  
Status: **completed**  
Stand: **2026-04-29**  
Basiert auf: **abgeschlossenem v0.4 Truth Layer**

## Leitprinzip

v0.5 baut keine Prognosemaschine.

v0.5 baut eine read-only Evidence Engine, die ehrlich zählt, was aus vorhandenen Daten belastbar ableitbar ist.

```text
Evidence Engine Lite darf nicht klug wirken.
Sie muss ehrlich zählen — und sichtbar machen, welche Zählung überhaupt zulässig ist.
```

Der Abschluss von v0.5 ist dokumentiert in:

```text
docs/v05_evidence_engine_closure_audit.md
```

---

## 1. Ziel

v0.5 beantwortet:

```text
Welche historischen Trade-Entscheidungssituationen hatten welche Outcomes?
Welche Samples sind voll anti-hindsight-validiert?
Welche Samples sind nur outcome-only?
Welche Samples sind auszuschließen?
```

Kernmodell:

```text
Trade = Entry-Kontext + späteres Outcome
```

Die Engine aggregiert Klassen von Situationen, keine Einzelfall-Anekdoten.

---

## 2. Nicht-Ziele

v0.5 enthält nicht:

```text
kein ML
keine LLM-Entscheidung
keine automatische Kauf-/Verkaufsentscheidung
keine Sizing-Automation
keine Broker-Integration
keine automatische Live-Gewichtsänderung
keine Portfolio-Rebalancing-Engine
keine Makro-Schicht
keine Mutation an trade_campaign oder trade_event
keine UI als Pflicht-Gate
keine Recommendation Engine
keine Forward-Return-Signal-Bucket-Entscheidungsebene
```

---

## 3. Read-only Prinzip

Erlaubt:

```text
SELECT aus trade_campaign
SELECT aus trade_event
SELECT aus instrument_*_snapshot
SELECT aus instrument
SELECT aus pipeline_run
EvidenceReadout als neutrale Datenstruktur erzeugen
```

Nicht erlaubt:

```text
UPDATE trade_campaign
UPDATE trade_event
DELETE/INSERT in Truth-Layer-Tabellen
Instrument-State ändern
Migrationen aus Evidence heraus starten
Apply-Kommandos
```

Evidence liest. Evidence entscheidet nicht. Evidence mutiert nicht.

---

## 4. Evidence-Klassen

### `eligible_full`

Bedeutung:

```text
Trade-Outcome valide.
Entry-Snapshots DB-level gegen Anti-Hindsight und Run-Provenance validiert.
```

Nur `eligible_full` darf später Eingang für signalbasierte Entry-Evidence werden.

### `eligible_outcome_only`

Bedeutung:

```text
Trade-Outcome valide.
Entry-Kontext fehlt, ist seed-basiert oder nicht vollständig anti-hindsight-validiert.
```

Darf in Outcome-Aggregationen erscheinen, aber nicht als vollwertige Signal-Evidence gelten.

### `excluded`

Bedeutung:

```text
Sample ist für Aggregation unzulässig.
```

Beispiele:

```text
open campaign
invalid_time_order
missing_closed_at
missing_pnl
unknown_state
```

---

## 5. Anti-Hindsight-Invarianten

Ein Snapshot darf nur `eligible_full` stützen, wenn alle Bedingungen erfüllt sind:

```text
snapshot_id vorhanden
snapshot row existiert
snapshot.instrument_id == sample.instrument_id
snapshot.source_run_id vorhanden
snapshot.available_at vorhanden
snapshot.available_at <= entry timestamp
pipeline_run existiert
pipeline_run.status = success
pipeline_run.exit_code = 0
pipeline_run.finished_at vorhanden
snapshot.available_at >= pipeline_run.finished_at
```

Wenn eine Bedingung fehlschlägt:

```text
eligible_outcome_only oder excluded
niemals eligible_full
```

Die technische Prüfung liegt in:

```text
web/src/Service/Evidence/SnapshotValidationService.php
```

---

## 6. Zentrale Datenmodelle

Kernmodelle:

```text
EvidenceTradeSample
EvidenceEligibilityStatus
EvidenceExclusionReason
EvidenceDataQualityFlag
EvidenceConfidenceLevel
EvidenceMetricSummary
EvidenceReadout
SnapshotValidationResult
```

### EvidenceTradeSample

Wichtige Felder:

```text
campaignId
instrumentId
tradeType
campaignState
openedAt
closedAt nullable
holdingDays nullable
realizedPnlGross nullable
realizedPnlNet nullable
realizedPnlPct nullable
entryEventId nullable
exitEventId nullable
exitReason nullable
buySignalSnapshotId nullable
sepaSnapshotId nullable
epaSnapshotId nullable
scoringVersion nullable
policyVersion nullable
modelVersion nullable
macroVersion nullable
seedSource: live | migration | manual | null
eligibilityStatus nullable
exclusionReason nullable
dataQualityFlags[]
```

Konvention:

```text
realizedPnlGross und realizedPnlNet sind Geldbeträge.
realizedPnlPct ist Ratio.
0.30 = 30 %
```

---

## 7. Trade Outcome Extractor

`TradeOutcomeExtractor` erzeugt `EvidenceTradeSample` aus terminalen Campaigns.

Terminale States:

```text
closed_profit
closed_loss
closed_neutral
returned_to_watchlist
```

Nicht-terminal:

```text
open
trimmed
paused
```

Grundregeln:

```text
closed_at muss vorhanden sein
closed_at >= opened_at
realized_pnl_pct wird als Ratio gelesen
realized_pnl_gross/net bleiben Geldbeträge
trade_type bleibt erhalten
migration_seed/manual_seed werden markiert
returned_to_watchlist ist abgeschlossen, aber eigene Outcome-Klasse
```

Wichtig:

```text
TradeOutcomeExtractor liest.
TradeOutcomeExtractor schreibt nicht.
```

---

## 8. Eligibility Evaluator

`EvidenceEligibilityEvaluator` klassifiziert Samples in:

```text
eligible_full
eligible_outcome_only
excluded
```

Regelreihenfolge:

```text
1. State-/Terminal-Prüfung
2. Zeitreihenfolge
3. Pflichtfelder closed_at / realized_pnl_pct
4. Seed-Downgrade
5. Missing-Snapshot-Downgrade
6. SnapshotValidationService-Downgrade bei invaliden Snapshots
7. nur dann eligible_full
```

Seeds bleiben konservativ:

```text
migration_seed → eligible_outcome_only
manual seed    → eligible_outcome_only
```

Auch wenn Snapshots später validierbar wären, machen Seeds keine vollwertige Entry-Evidence.

---

## 9. Snapshot Validation Foundation

Snapshot-Tabellen:

```text
instrument_buy_signal_snapshot
instrument_sepa_snapshot
instrument_epa_snapshot
```

v0.5-Provenance-Felder:

```text
source_run_id → pipeline_run.id
available_at → Zeitpunkt, ab dem der Snapshot verfügbar ist
```

`pipeline_run` bleibt die kanonische Run-Provenance. Es gibt keine zweite Evidence-spezifische Run-Tabelle.

### SEPA / EPA

SEPA und EPA schreiben `source_run_id` beim Write und finalisieren `available_at` erst nach erfolgreichem Run:

```text
finalize_snapshots_for_run(source_run_id, finished_at)
```

### BuySignal

BuySignal schreibt `source_run_id` und `available_at` direkt aus dem Run-Kontext.

Der Writer allein ist nicht das Eligibility-Gate. Die finale Freigabe erfolgt immer über `SnapshotValidationService`.

---

## 10. Writer-Immutability

Finalisierte Snapshot-Zeilen sind immutable.

Wenn `available_at IS NOT NULL` gilt:

```text
Business-Felder dürfen nicht mehr überschrieben werden.
source_run_id darf nicht mehr wechseln.
available_at bleibt stabil.
updated_at bleibt stabil.
```

Wenn `available_at IS NULL` gilt:

```text
Business-Felder bleiben reparierbar.
source_run_id darf auf neuen non-null Run wechseln.
NULL source_run_id darf bestehende source_run_id nicht löschen.
```

Diese Invariante verhindert:

```text
neuer Inhalt + alte Verfügbarkeit = Hindsight-Korruption
```

Abgesichert durch:

```text
stock-system/tests/test_sepa_snapshot_writer.py
stock-system/tests/test_epa_snapshot_writer.py
stock-system/tests/test_buy_signal_snapshot_writer.py
stock-system/tests/test_sepa_snapshot_integration.py
stock-system/tests/test_epa_snapshot_integration.py
stock-system/tests/test_buy_signal_snapshot_integration.py
```

---

## 11. Aggregation Rules

Aggregatoren zählen. Sie entscheiden nicht.

Pflicht pro Aggregation:

```text
bucketKey
bucketLabel
n
winRate
avgReturn
minReturn
maxReturn
confidenceLevel
eligibleFullCount
outcomeOnlyCount
excludedCount
dataQualityFlags
```

Entry- und Exit-Aggregatoren dürfen Outcome-Metriken über `eligible_full` und `eligible_outcome_only` bilden, müssen die Zusammensetzung aber sichtbar halten.

Signalbasierte Entry-Evidence in v0.6 darf dagegen nur `eligible_full` verwenden.

---

## 12. Confidence Rules

Confidence ist eine Evidence-Stufe, kein statistisches Wahrheitszertifikat.

```text
n < 5      → anecdotal
5–19       → very_low
20–49      → low
50–99      → medium
100+       → high
```

Immer ausgeben:

```text
n
avg_return
min_return
max_return
confidence_level
```

Ab `n >= 5` zusätzlich:

```text
standard_deviation
standard_error_of_mean
```

Warnflags:

```text
low_confidence_evidence
contains_outcome_only_samples
no_full_entry_evidence
contains_excluded_samples
```

---

## 13. Readout Architektur

`EvidenceReadoutBuilder` erzeugt neutrale Readouts.

Erlaubt:

```text
Warnings
Counts
Aggregierte Metriken
Data-Quality-Codes
```

Nicht erlaubt:

```text
Kaufen
Verkaufen
Trimmen
Rating
Score-Empfehlung
```

Der Readout bleibt maschinenlesbar und recommendation-frei.

---

## 14. Implemented Chunks

```text
C1   Evidence Read Models                         completed
C2   TradeOutcomeExtractor                        completed
C3   EvidenceEligibilityEvaluator                 completed
C4   EntryEvidenceAggregator                      completed
C5   ExitEvidenceAggregator                       completed
C6   EvidenceConfidenceCalculator                 completed
C7   EvidenceReadoutBuilder                       completed
C8   Validation Fixtures / Poison Pills           completed
C9   Snapshot Validation Foundation               completed
C10a Writer Provenance + Immutability             completed
C10b SnapshotValidationService                    completed
C10c Eligibility Integration                      completed
C10d Writer-Immutability Tests                    completed
```

---

## 15. Tests

Relevante PHP-Testgruppen:

```text
web/tests/Service/Evidence/EvidenceEligibilityEvaluatorTest.php
web/tests/Service/Evidence/SnapshotValidationServiceTest.php
web/tests/Service/Evidence/EvidenceReadoutBuilderTest.php
web/tests/Service/Evidence/EntryEvidenceAggregatorTest.php
web/tests/Service/Evidence/ExitEvidenceAggregatorTest.php
web/tests/Service/Evidence/TradeOutcomeExtractorIntegrationTest.php
```

Relevante Python-Testgruppen:

```text
stock-system/tests/test_sepa_snapshot_writer.py
stock-system/tests/test_epa_snapshot_writer.py
stock-system/tests/test_buy_signal_snapshot_writer.py
stock-system/tests/test_sepa_snapshot_integration.py
stock-system/tests/test_epa_snapshot_integration.py
stock-system/tests/test_buy_signal_snapshot_integration.py
```

MariaDB-Integrationstests müssen gegen `stock_project_test` laufen und verweigern andere Datenbanken.

---

## 16. Known Non-Blockers

```text
EvidenceTradeSample nutzt openedAt als Entry-Zeitpunkt.
Der kanonische TradeEventWriter setzt opened_at aus event_timestamp.
Langfristig kann ein expliziter entryEventTimestamp sinnvoll sein.

BuySignal Snapshot bleibt DBAL/table-only ohne Doctrine Entity.
SnapshotValidationService validiert BuySignal bewusst direkt per DBAL.

Alte Snapshots ohne Provenance bleiben eligible_outcome_only.
Kein Backfill ist für v0.5 erforderlich.

Snapshot-Versionierung für Policy/Scoring bleibt v0.6/v0.7-Follow-up.
```

---

## 17. v0.5 Definition of Done

```text
Trade Evidence read-only
Eligibility/Exclusion sichtbar
eligible_full nur über DB-validierte Snapshots
Anti-Hindsight DB-level validiert
Confidence mit n und Streuung
Readout mit Warning-Codes
Keine Mutation am Truth Layer
Keine Recommendation-Semantik
Writer-Provenance vorhanden
Writer-Immutability getestet
```

Status:

```text
v0.5 closed = yes
```

---

## 18. Gate zu v0.6

v0.6 darf starten.

Zwingende Regeln:

```text
eligible_full bleibt einziger Eingang für signalbasierte Entry-Evidence.
eligible_outcome_only darf nicht still als Signal-Evidence behandelt werden.
Keine Buy/Sell-UI.
Keine Recommendation Engine.
Keine Forward-Return-Leakage.
Keine Rücknahme der C10d-Immutability-Regeln.
```

v0.6 baut nicht den Trader.  
v0.6 baut die erste echte Signal-Bucket-Evidence-Schicht.
