# ARCHITECTURE v0.5 – Evidence Engine Lite

stock-project · v0.5 · Architekturvertrag  
Status: **active**  
Stand: **2026-04-27**  
Basiert auf: **abgeschlossenem v0.4 Truth Layer**

## Leitprinzip

v0.5 baut keine Prognosemaschine.

v0.5 baut eine read-only Evidence Engine, die ehrlich zählt, was aus vorhandenen Daten belastbar ableitbar ist.

```text
Evidence Engine Lite darf nicht klug wirken.
Sie muss ehrlich zählen — und sichtbar machen, welche Zählung überhaupt zulässig ist.
```

---

## 1. Ziel

v0.5 beantwortet:

```text
Was lässt sich aus vergangenen Trade-Outcomes und vorhandenen Signalzuständen belastbar lernen?
```

Zwei Evidence-Arten:

```text
1. Trade Outcome Evidence
   aus abgeschlossenen trade_campaign / trade_event

2. Signal Forward-Return Evidence
   aus zeitlich bekannten Signalzuständen mit späterem Forward Return
```

Diese Quellen werden nie still vermischt.

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
```

---

## 3. Read-only Prinzip

Erlaubt:

```text
SELECT aus trade_campaign
SELECT aus trade_event
SELECT aus instrument_*_snapshot
SELECT aus instrument
Console-/Markdown-Ausgabe erzeugen
Report-Dateien erzeugen
```

Nicht erlaubt:

```text
UPDATE trade_campaign
UPDATE trade_event
DELETE/INSERT in Truth-Layer-Tabellen
Instrument-State ändern
Migrationen
Apply-Kommandos
```

---

## 4. Evidence Sources

### 4.1 `trade_outcome`

Quelle:

```text
trade_campaign
trade_event
trade_migration_log
instrument
Snapshot-IDs auf trade_event
```

Beantwortet:

```text
Wie gut waren live/paper/pseudo Entscheidungen?
Welche Entry-/Exit-Pfade führten zu welchen Outcomes?
Welche Exit Reasons waren teuer oder nützlich?
```

### 4.2 `signal_forward_return`

Quelle:

```text
zeitlich bekannte Signalzustände
spätere Forward Returns
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

Beantwortet:

```text
Wie verhielten sich Signalzustände danach am Markt?
Hatten bestimmte Score-/Signal-Buckets bessere Forward Returns?
```

Startumfang:

```text
sourceTable = instrument_sepa_snapshot
signalSource = sepa
horizonDays = 5
forward_return_5d, falls vorhanden
Score-Bucket aus vorhandenen SEPA-Score-Feldern
```

Die Architektur bleibt quellenagnostisch. Weitere Signalquellen werden durch neue Extractor-Chunks angeschlossen, nicht durch Umbau der Evidence Engine.

---

## 5. Zentrale Datenmodelle

Bereits angelegt in C1:

```text
EvidenceSource
EvidenceEligibilityStatus
EvidenceExclusionReason
EvidenceDataQualityFlag
EvidenceConfidenceLevel
EvidenceTradeSample
EvidenceSignalSample
EvidenceMetricSummary
SignalSource
SignalFamily
```

### EvidenceSource

```text
trade_outcome
signal_forward_return
```

### SignalSource

```text
sepa
epa
buy_signal
kronos
sentiment
custom
```

### SignalFamily

```text
structure
execution
risk
sentiment
composite
unknown
```

### EvidenceTradeSample

Mindestfelder:

```text
campaignId
instrumentId
tradeType
campaignState
openedAt
closedAt nullable
holdingDays nullable
totalQuantity
openQuantity
avgEntryPrice
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

### EvidenceSignalSample

Mindestfelder:

```text
signalSource
signalFamily nullable
sourceTable nullable
sourceId
instrumentId
asOfAt
horizonDays nullable
forwardReturnPct nullable
score nullable
scoreBucket nullable
signalVersion nullable
detailRef nullable
eligibilityStatus nullable
exclusionReason nullable
dataQualityFlags[]
```

Konvention:

```text
forwardReturnPct ist Ratio.
0.08 = 8 %
```

---

## 6. Trade Outcome Extractor

C2 erzeugt `EvidenceTradeSample` aus terminalen Campaigns.

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

---

## 7. Eligibility + Anti-Hindsight Core

Anti-Hindsight gehört in Extractor und Eligibility, nicht erst in ein spätes Report-Gate.

Eligibility:

```text
eligible_full
eligible_outcome_only
eligible_snapshot_only
excluded
```

Exclusion Reasons:

```text
open_campaign
invalid_time_order
missing_closed_at
missing_pnl
missing_required_snapshot
snapshot_after_event
snapshot_instrument_mismatch
migration_seed_entry_unusable
manual_seed_warning
unknown_state
unsupported_trade_type
missing_forward_return
missing_score
```

Anti-Hindsight-Regeln:

```text
snapshot.as_of_date <= event_timestamp
snapshot.instrument_id == trade.instrument_id
Signal as_of_at <= measured outcome horizon start
```

Wenn Snapshot-/Signal-Kontext nicht validiert wird, darf er nicht still `eligible_full` erzeugen.

Legacy-Regel:

```text
migration_seed mit Snapshot NULL darf nicht als normaler Entry-Evidence-Fall behandelt werden.
```

---

## 8. Signal Evidence Extractor

C2b heißt konzeptionell:

```text
Signal Snapshot Evidence Extractor
```

Start-Scope:

```text
signalSource = sepa
sourceTable = instrument_sepa_snapshot
horizonDays = 5
forward_return_5d lesen
Score-Buckets bilden
```

Nicht in C2b:

```text
keine Kronos-Extraktion
keine Sentiment-Extraktion
keine neue generische Signal-Tabelle
keine Berechnung neuer Forward Returns
keine Campaign-Verknüpfung
```

Spätere mögliche Extractor-Chunks:

```text
Kronos Signal Evidence Extractor
Sentiment Signal Evidence Extractor
Buy-Signal Evidence Extractor
EPA Signal Evidence Extractor
```

Nur anschließen, wenn Signalquelle zeitlich sauber ist:

```text
instrument_id vorhanden
as_of_at/as_of_date vorhanden
Signalversion oder Herkunft nachvollziehbar
kein Hindsight
Forward Return vorhanden oder sauber berechenbar
```

---

## 9. Aggregation Rules

Aggregatoren zählen. Sie entscheiden nicht.

Pflicht pro Aggregation:

```text
source
signalSource nullable
bucketKey
bucketLabel
timeWindow
horizonDays nullable
n
avgReturn
minReturn
maxReturn
confidenceLevel
dataQualityFlags
exclusion summary
```

Zeitfenster:

```text
all_time
last_12_months
before_last_12_months
```

---

## 10. Confidence Rules

Confidence ist eine Evidence-Stufe, kein statistischer 95%-Koeffizient.

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
low_sample_size
high_variance
wide_min_max_range
contains_seed_data
mixed_periods
snapshot_incomplete
```

---

## 11. Report Architektur

Report-Logik gehört nicht in Command oder Controller.

```text
EvidenceReportService
  → liefert reine Report-Datenstruktur

EvidenceReportCommand
  → dünner Adapter für Console/Markdown

EvidenceController später
  → dünner Adapter für UI
```

Report-Inhalte:

```text
Dataset Summary
Trade Evidence
Signal Evidence
Exclusions
Confidence Warnings
Time Windows
Anti-Hindsight Notes
Evidence Run Fingerprint
```

---

## 12. Validation Fixtures / Poison Pills

Fixtures müssen vor Aggregatoren belastbar sein.

Pflichtszenarien:

```text
closed_profit
closed_loss
closed_neutral
returned_to_watchlist
paper closed_profit
migration_seed
open campaign
missing snapshot
```

Poison Pills:

```text
opened_at > closed_at
snapshot.as_of_date > event_timestamp
snapshot.instrument_id != campaign.instrument_id
terminal campaign ohne realized_pnl_pct
migration_seed mit Snapshot NULL
```

Anforderung:

```text
System darf nicht crashen.
Es muss ausschließen und begründen.
```

---

## 13. Chunk Plan

```text
C1  Evidence Read Models                         completed
C2  Closed Trade Outcome Extractor               active
C3  Eligibility + Anti-Hindsight Core            planned
C6  Confidence Calculator                         planned
C8  Validation Fixtures / Poison Pills           planned
C2b Signal Snapshot Evidence Extractor           planned
C4  Entry Evidence Aggregator                     planned
C5  Exit Evidence Aggregator                      planned
C7  Evidence Report Service + Command            planned
C10 Evidence Audit Gate                           planned
C9  Minimal UI                                    optional
```

---

## 14. Review-Regeln für v0.5 PRs

```text
1. Ist der Chunk read-only?
2. Werden trade types getrennt?
3. Werden migration_seed/manual_seed korrekt behandelt?
4. Werden offene Campaigns ausgeschlossen?
5. Sind n und confidence sichtbar, wo aggregiert wird?
6. Gibt es Exclusion Reasons?
7. Wird Hindsight früh ausgeschlossen oder konservativ begrenzt?
8. Gibt es heimliche UI-/Migration-/Scope-Erweiterung?
9. Sind Tests schema-kompatibel und deterministisch?
10. Bleibt der Diff eng?
```

---

## 15. Definition of Done v0.5

```text
C1 grün
C2 grün
C3 grün
C6 grün
C8 grün
C2b grün
C4 grün
C5 grün
C7 grün
C10 grün
```

C9 UI ist kein Abschlusskriterium.

```text
v0.5 baut nicht den besseren Trader.
v0.5 baut die erste ehrliche Lernschicht.
```
