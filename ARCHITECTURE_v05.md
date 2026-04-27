# ARCHITECTURE v0.5 – Evidence Engine Lite

stock-project · v0.5 · Architekturvertrag  
Status: **active planning**  
Stand: **2026-04-27**  
Basiert auf: **abgeschlossenem v0.4 Truth Layer**

## Leitprinzip

v0.5 baut keine Prognosemaschine.

v0.5 baut eine read-only Evidence Engine, die ehrlich zählt, was aus vorhandenen Daten belastbar ableitbar ist.

Der zentrale Satz:

```text
Evidence Engine Lite darf nicht klug wirken.
Sie muss ehrlich zählen — und sichtbar machen, welche Zählung überhaupt zulässig ist.
```

---

## 1. Ziel

v0.5 beantwortet:

```text
Was lässt sich aus vergangenen Trade-Outcomes und vorhandenen Snapshots belastbar lernen?
```

Das System nutzt zwei Evidence-Quellen:

```text
1. Trade Evidence
   aus abgeschlossenen trade_campaign / trade_event

2. Snapshot Evidence
   aus SEPA/EPA/Buy-Signal-Snapshots mit Forward Returns
```

Diese Quellen werden nie still vermischt.

---

## 2. Nicht-Ziele

v0.5 enthält ausdrücklich nicht:

- kein ML
- keine LLM-Entscheidung
- keine automatische Kauf-/Verkaufsentscheidung
- keine Sizing-Automation
- keine Broker-Integration
- keine automatische Live-Gewichtsänderung
- keine Portfolio-Rebalancing-Engine
- keine Makro-Schicht
- keine Mutation an `trade_campaign` oder `trade_event`
- keine Evidence-Persistenz als Pflicht
- keine UI als Pflicht-Gate

---

## 3. Read-only Prinzip

v0.5 ist read-only gegenüber dem Truth Layer.

Erlaubt:

```text
SELECT aus trade_campaign
SELECT aus trade_event
SELECT aus instrument_*_snapshot
SELECT aus instrument
Report-Dateien erzeugen
Console-/Markdown-Ausgabe erzeugen
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
instrument
Snapshot-IDs auf trade_event
```

Beantwortet:

```text
Wie gut waren echte/paper/pseudo Entscheidungen?
Welche Entry-/Exit-Pfade führten zu welchen Outcomes?
Welche Exit Reasons waren teuer oder nützlich?
```

### 4.2 `snapshot_forward_return`

Quelle:

```text
instrument_sepa_snapshot
instrument_epa_snapshot
instrument_buy_signal_snapshot
```

Beantwortet:

```text
Wie verhielten sich Snapshot-Zustände danach am Markt?
Hatten bestimmte Score-Buckets bessere Forward Returns?
```

Startumfang v0.5:

```text
instrument_sepa_snapshot
forward_return_5d, falls vorhanden
SEPA total_score Buckets
```

EPA und Buy-Signal Snapshot Evidence können folgen, aber sind nicht Startvoraussetzung.

---

## 5. Zentrale Datenmodelle

Die Implementierung soll kleine DTO-/Read-Model-Klassen verwenden, keine rohen DB-Arrays durch alle Services tragen.

Mindestmodelle:

```text
EvidenceSource
EvidenceEligibilityStatus
EvidenceExclusionReason
EvidenceDataQualityFlag
EvidenceTradeSample
EvidenceSnapshotSample
EvidenceMetricSummary
EvidenceReport
```

### 5.1 EvidenceSource

```text
trade_outcome
snapshot_forward_return
```

### 5.2 EvidenceTradeSample

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
realizedPnlPct ist Ratio.
0.30 = 30 %
```

### 5.3 EvidenceSnapshotSample

Mindestfelder:

```text
snapshotType: sepa | epa | buy_signal
snapshotId
instrumentId
asOfDate
horizonDays
forwardReturnPct nullable
scoreBucket nullable
totalScore nullable
sourceVersion nullable
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

Der Trade Outcome Extractor erzeugt `EvidenceTradeSample` aus terminalen Campaigns.

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

Nicht-terminale Campaigns werden nicht als Outcome-Samples verwendet.

Grundregeln:

```text
1. closed_at muss vorhanden sein.
2. closed_at >= opened_at.
3. realized_pnl_pct wird als Ratio gelesen.
4. trade_type bleibt erhalten.
5. migration_seed/manual_seed werden markiert.
6. returned_to_watchlist ist abgeschlossen, aber eigene Outcome-Klasse.
```

---

## 7. Eligibility Rules + Anti-Hindsight Core

Anti-Hindsight ist kein späteres Report-Feature. Es gehört in Extractor und Eligibility.

### 7.1 Eligibility Status

```text
eligible_full
eligible_outcome_only
eligible_snapshot_only
excluded
```

Zusätzlich können `dataQualityFlags[]` mehrere Einschränkungen tragen.

Beispiel:

```text
eligibility_status = eligible_outcome_only
flags = [migration_seed, missing_entry_snapshot]
```

### 7.2 Exclusion Reasons

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
invalid_as_of_date
```

### 7.3 Anti-Hindsight-Regeln

Trade Entry Evidence:

```text
entry_snapshot.as_of_date <= entry_event.event_timestamp
entry_snapshot.instrument_id == campaign.instrument_id
```

Trade Exit Evidence:

```text
exit_snapshot.as_of_date <= exit_event.event_timestamp
exit_snapshot.instrument_id == campaign.instrument_id
```

Snapshot Evidence:

```text
forward_return_Xd darf nur genutzt werden, wenn vorhanden.
v0.5 berechnet keine neuen Forward Returns nach, sofern keine vorhandene getestete Logik dafür existiert.
```

Legacy-Regel:

```text
migration_seed mit Snapshot NULL darf nicht als normaler Entry-Evidence-Fall behandelt werden.
```

---

## 8. Snapshot Evidence Extractor

Startumfang:

```text
Source: instrument_sepa_snapshot
Horizon: forward_return_5d, falls vorhanden
Bucket: total_score
```

Score-Buckets:

```text
0–39
40–59
60–74
75–84
85+
unknown
```

Exclusions:

```text
missing_forward_return
missing_score
invalid_as_of_date
```

Nicht-Ziele:

```text
Keine Campaign-Verknüpfung.
Keine Entry/Exit-Auswertung.
Keine Prognose.
Keine UI.
Keine Berechnung neuer Forward Returns.
```

---

## 9. Aggregation Rules

Aggregatoren zählen Evidence-Samples. Sie entscheiden nicht.

### 9.1 Entry Evidence Aggregator

Quelle:

```text
EvidenceTradeSample mit geeigneter Eligibility
```

Start-Buckets:

```text
trade_type
entry snapshot availability
SEPA total bucket, falls vorhanden
EPA action/bucket, falls vorhanden
outcome class
```

### 9.2 Exit Evidence Aggregator

Quelle:

```text
EvidenceTradeSample mit terminalem Exit-Event
```

Buckets:

```text
exit_reason
final_state
trade_type
```

### 9.3 Snapshot Evidence Aggregator

Quelle:

```text
EvidenceSnapshotSample
```

Buckets:

```text
snapshot_type
score_bucket
horizon_days
```

### 9.4 Zeitdimension

Jede Aggregation soll mindestens diese Zeitfenster unterstützen:

```text
all_time
last_12_months
before_last_12_months
```

Quartals-Buckets sind optional und nicht v0.5-Pflicht.

---

## 10. Confidence Rules

Confidence ist kein Schönfärber. Sie ist eine Bremse.

Basis-Niveaus:

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

Keine Wahrscheinlichkeit ohne:

```text
n
confidence_level
source
time_window
exclusion_summary
```

---

## 11. Report Architektur

Report-Logik gehört nicht in den Symfony Command und nicht in einen Controller.

Richtige Struktur:

```text
EvidenceReportService
  → liefert reine Report-Datenstruktur

EvidenceReportCommand
  → dünner Adapter für Console/Markdown

EvidenceController später
  → dünner Adapter für UI
```

C7 ist deshalb:

```text
Evidence Report Service + Command
```

Nicht nur Command.

### Report-Inhalte

```text
Dataset Summary
Trade Evidence
Snapshot Evidence
Exclusions
Confidence Warnings
Time Windows
Anti-Hindsight Notes
Evidence Run Fingerprint
```

### Evidence Run Fingerprint

Der Report soll einen Fingerprint enthalten.

Hash-Basis:

```text
eligibility_rules_version
confidence_rules_version
aggregation_rules_version
source_filters
trade_type_filter
report_generated_at
```

In v0.5 muss dieser Fingerprint nicht persistiert werden. Anzeige im Report reicht.

---

## 12. Validation Fixtures / Poison Pills

Fixtures müssen vor den Aggregatoren belastbar sein.

Pflichtszenarien:

```text
3 live closed_profit
2 live closed_loss
1 returned_to_watchlist
1 paper closed_profit
1 migration_seed
1 open campaign
1 missing snapshot
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

## 13. UI-Entscheidung

C9 Minimal UI ist optional.

v0.5 ist nicht davon abhängig.

Pflicht für v0.5:

```text
EvidenceReportService
EvidenceReportCommand
Markdown/Console Report
```

Optional später:

```text
/evidence
read-only Twig-View
```

Keine UI darf anzeigen:

```text
Top Picks
Buy Probability
Sell Probability
versteckte Empfehlungen
Ampel, die Entscheidung simuliert
```

---

## 14. Chunk Plan

### Phase 1 — Fundament

```text
C1 Evidence Read Models
C2 Closed Trade Outcome Extractor
C6 Confidence Calculator
```

C1 zuerst. Danach C2 und C6 parallel möglich.

### Phase 2 — Filter und Testdaten

```text
C3 Eligibility Rules + Anti-Hindsight Core
C8 Validation Fixtures
```

### Phase 3 — Snapshot Quickwin und Aggregatoren

```text
C2b SEPA Snapshot Evidence Extractor
C4 Entry Evidence Aggregator
C5 Exit Evidence Aggregator
```

### Phase 4 — Report

```text
C7 Evidence Report Service + Command
```

### Phase 5 — Audit Gate

```text
C10 Evidence Audit Gate
```

Optional:

```text
C9 Minimal Evidence UI
```

---

## 15. Risikogewichte

```text
C1  Evidence Read Models                         sehr wichtig
C2  Closed Trade Outcome Extractor               extrem
C3  Eligibility + Anti-Hindsight Core            extrem
C6  Confidence Calculator                         sehr wichtig
C8  Validation Fixtures / Poison Pills           sehr wichtig
C2b SEPA Snapshot Evidence Extractor             sehr wichtig
C4  Entry Evidence Aggregator                     extrem
C5  Exit Evidence Aggregator                      extrem
C7  Evidence Report Service + Command            wichtig
C10 Evidence Audit Gate                           extrem
C9  Minimal UI                                    optional / wichtig
```

---

## 16. Parallelisierungspfad

```text
🟢 v0.4 Truth Layer abgeschlossen
  │
  ▼
⚪ C1 Evidence Read Models
  │
  ├───────────────┐
  ▼               ▼
⚪ C2 Trade Outcome Extractor     ⚪ C6 Confidence Calculator
  │                               │
  └───────────────┬───────────────┘
                  ▼
⚪ C3 Eligibility + Anti-Hindsight Core
  │
  ├───────────────┐
  ▼               ▼
⚪ C8 Validation Fixtures          ⚪ C2b SEPA Snapshot Evidence Extractor
  │                               │
  ├───────────────┬───────────────┘
  ▼               ▼
⚪ C4 Entry Aggregator             ⚪ C5 Exit Aggregator
  │               │
  └───────┬───────┘
          ▼
⚪ C7 Evidence Report Service + Command
  │
  ▼
⚪ C10 Evidence Audit Gate
  │
  ▼
🟢 v0.5 Evidence Engine Lite abgeschlossen

Optional:
⚪ C9 Minimal UI
```

---

## 17. Review-Regeln für v0.5 PRs

Jeder PR wird geprüft gegen:

```text
1. Ist der Chunk read-only?
2. Werden trade types getrennt?
3. Werden migration_seed/manual_seed korrekt behandelt?
4. Werden offene Campaigns ausgeschlossen?
5. Sind n und confidence sichtbar?
6. Gibt es Exclusion Reasons?
7. Wird Hindsight früh ausgeschlossen?
8. Gibt es heimliche UI-/Migration-/Scope-Erweiterung?
9. Sind Tests klein und deterministisch?
10. Bleibt der Diff eng?
```

Urteile:

```text
APPROVE
REQUEST CHANGES
COMMENT / CONDITIONAL APPROVE
```

---

## 18. Abschlusskriterien für v0.5

v0.5 gilt als abgeschlossen, wenn:

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

Finaler Satz:

```text
v0.5 baut nicht den besseren Trader.
v0.5 baut die erste ehrliche Lernschicht.
```
