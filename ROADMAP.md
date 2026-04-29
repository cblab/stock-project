# ROADMAP.md

Stand: 2026-04-29  
Status: v0.5 abgeschlossen, v0.6 nächster aktiver Entwicklungsabschnitt

## Nordstern

**stock-project v1.0** ist ein lokaler, erklärbarer Decision Assistant für Aktien. Das System verbindet Signalzustände, Trade-History, Snapshot-Evidence, Makro-Kontext und Portfolio-Risiko zu auditierbaren Entscheidungshilfen.

Das System ist **kein autonomer Trader**.

```text
Keine Broker-Anbindung.
Keine automatische Live-Gewichtsänderung.
Kein LLM als numerischer Entscheider.
Keine Anlageberatung-Automation.
Der Nutzer entscheidet final.
```

---

## Leitprinzipien

1. **Truth before intelligence**
   - Erst echte Trade-History, dann Evidence, dann Decision Workbench.

2. **Evidence before probability**
   - Jede Aussage braucht `n`, Return-Verteilung, Zeithorizont, Datenquelle und Evidenzklasse.

3. **No fake certainty**
   - Kleine Fallzahlen erzeugen Hinweise, keine Regeln.

4. **Snapshot timing is law**
   - Kein Snapshot darf als damalige Wahrheit gelten, wenn seine Verfügbarkeit zum Entry-Zeitpunkt nicht nachweisbar ist.

5. **Version everything**
   - `scoring_version`, `policy_version`, `model_version`, `macro_version` bleiben Teil des Kontexts.

6. **Live/Paper/Pseudo getrennt**
   - Keine stille Vermischung von Datenregimen.

7. **Macro is a filter, not an oracle**

8. **LLMs explain, they do not decide**

9. **Read-only Evidence**
   - Evidence liest Truth Layer und Snapshots. Evidence verändert keine Trade-Wahrheit.

---

## Aktueller Status

```text
v0.4 Truth Layer:                 completed
v0.5 Evidence Engine Lite:        completed
v0.6 Signal Evidence Layer:       next
v0.7 Exit Engine & Portfolio Core planned
v0.8 Macro Layer:                 planned
v0.9 LLM / Analyst Layer:         planned
v1.0 Decision Workbench:          target
```

Kanonische Architekturdateien:

```text
ARCHITECTURE_v04.md
ARCHITECTURE_v05.md
docs/v05_evidence_engine_closure_audit.md
```

Operativer Navigationsanker:

```text
docs/stock-project-project-map.md
```

---

## v0.4 – Truth Layer

Status: **completed**

Gebaut:

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

Finale Entscheidungen:

```text
realized_pnl_pct ist Ratio:
0.30 = 30 %

hard_exit:
instrument.is_portfolio = 0
instrument.active = 1

return_to_watchlist:
instrument.is_portfolio = 0
instrument.active = 1

trim:
instrument.is_portfolio bleibt 1
```

Legacy Seed:

```text
21 bestehende Positionen wurden als migration_seed migriert.
Snapshot-IDs wurden bei Hindsight-Risiko bewusst NULL gelassen.
```

Canonical architecture:

```text
ARCHITECTURE_v04.md
```

---

## v0.5 – Evidence Engine Lite

Status: **completed**

Canonical architecture and closure audit:

```text
ARCHITECTURE_v05.md
docs/v05_evidence_engine_closure_audit.md
```

### Ziel

v0.5 baut die erste ehrliche Lernschicht über abgeschlossene Trade-Entscheidungen.

Kernmodell:

```text
Trade = Signalzustand zum Entry + späteres Outcome
```

v0.5 beantwortet nicht „Was soll ich kaufen?“, sondern:

```text
Welche Klassen historischer Entscheidungssituationen hatten welche Outcomes?
Welche Samples sind voll validiert?
Welche Samples sind nur outcome-only?
Welche Samples sind auszuschließen?
```

### Evidenzklassen

```text
eligible_full
  Outcome valide und Entry-Snapshots DB-level anti-hindsight-validiert.

eligible_outcome_only
  Outcome valide, aber Entry-Kontext nicht voll validiert, seed-basiert oder unvollständig.

excluded
  Sample darf nicht aggregiert werden.
```

### Anti-Hindsight-Invarianten

Ein Snapshot darf nur `eligible_full` stützen, wenn:

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

Wenn eine dieser Bedingungen nicht erfüllt ist:

```text
eligible_outcome_only oder excluded
niemals eligible_full
```

### Umgesetzte Chunks

```text
C1   Evidence Read Models                         completed
C2   TradeOutcomeExtractor                        completed
C3   EvidenceEligibilityEvaluator                 completed
C4   EntryEvidenceAggregator                      completed
C5   ExitEvidenceAggregator                       completed
C6   EvidenceConfidenceCalculator                 completed
C7   EvidenceReadoutBuilder                       completed
C8   Test Fixtures / Poison Pills                 completed
C9   Snapshot Validation Foundation               completed
C10a Writer Provenance + Immutability             completed
C10b SnapshotValidationService                    completed
C10c Eligibility Integration                      completed
C10d Writer-Immutability Tests                    completed
```

### Nicht-Ziele von v0.5

```text
keine Buy/Sell-Empfehlungen
keine Recommendation Engine
keine UI/API als Pflicht-Gate
keine DB-Persistenz der Readouts
kein Backfill alter Snapshots zu eligible_full
keine Forward-Return-Signal-Buckets als aktive Entscheidungsebene
keine Broker-Automation
keine Sizing-Automation
keine automatische Live-Gewichtsänderung
```

### Definition of Done

```text
Truth-Layer read-only
Outcome-Samples extrahierbar
Eligibility sichtbar
eligible_full nur mit DB-validierten Snapshots
eligible_outcome_only konservativ für unvollständige/alte/Seed-Kontexte
excluded für invalide Samples
Aggregation nach Entry/Exit-Kontext
Confidence mit n und Streuung
Readout mit maschinenlesbaren Warning-Codes
Writer-Provenance vorhanden
Finalisierte Snapshot-Zeilen immutable
```

v0.5 ist geschlossen.

---

## v0.6 – Signal Evidence Layer

Status: **next**

### Ziel

v0.6 baut auf v0.5 auf und aggregiert **Signal-Klassen** aus voll validierten Entry-Kontexten.

Nicht exakte Einzelzustände werden verglichen, sondern Buckets:

```text
SEPA >= 75
EPA >= 70
BuySignal decision = ENTRY/WATCH
kombinierte Signalprofile
```

Die zentrale Regel:

```text
Nur eligible_full darf Eingang für signalbasierte Entry-Evidence sein.
eligible_outcome_only darf nicht still als Signal-Evidence verwendet werden.
```

### v0.6 Scope

```text
Signal Bucket Read Models
SEPA Bucket Aggregation
EPA Bucket Aggregation
optional BuySignal Bucket Aggregation
Bucket-Key-Normalisierung
n / winRate / avgReturn / min / max / confidence
Warnings bei niedrigem n und gemischter Datenqualität
```

### Nicht enthalten

```text
keine Recommendation Engine
keine Buy/Sell-UI
keine automatische Trades
keine Forward-Return-Leakage in Entry-Evidence
keine Hindsight-Rückvalidierung alter Snapshots
```

---

## v0.7 – Exit Engine & Portfolio Core

Status: **planned**

Ziel:

```text
v0.4 Truth Layer + v0.5/v0.6 Evidence als Kontext für Exit-/Trim-/Hold-Entscheidungslogik nutzen.
```

Mögliche Bausteine:

```text
hold / watch_tightly / trim / hard_exit / pause
Positionsgrößen-Hinweise
Konzentrationswarnungen
Cluster-/Korrelationswarnungen
Add/Trim-Rebalancing-Logik
Daily Monitoring View
Open Position Monitor
```

Nicht enthalten:

```text
Auto-Trading
Broker-Anbindung
automatische Verkäufe
```

---

## v0.8 – Macro Layer

Status: **planned**

Bauen:

```text
macro_snapshot
Last-known-available Policy
VIX
Yield Curve / Term Spread
CPI YoY
PMI / ISM
Sector Rotation
Regime Labels
```

Regel:

```text
Makro nur mit zum Trade-Zeitpunkt verfügbaren Daten.
```

---

## v0.9 – LLM / Analyst Layer

Status: **planned**

Bauen:

```text
Daily Briefing
Portfolio-Coach
Konflikt-/Agreement-Erklärungen
LLM nur für sprachliche Interpretation
Policy-Profile als optionale Advisor-Profile
```

Regel:

```text
Zahlen und Regeln deterministisch.
LLM erklärt und prüft Widersprüche.
LLM entscheidet nicht numerisch.
```

---

## v1.0 – Decision Workbench / Closed Decision Loop

Status: **target**

Definition:

```text
Research
→ Candidate
→ Thesis
→ Entry
→ Monitoring
→ Hold/Trim/Exit/Return
→ Outcome
→ Evidence Learning
→ bessere nächste Entscheidung
```

Nicht Teil von v1.0:

```text
autonomer Handel
Broker-Anbindung
RL / FinRL
automatische Live-Gewichtsänderung
LLM als numerischer Entscheider
```

---

## Self-Learning Gate

Self-Learning bleibt deaktiviert, bis gleichzeitig erfüllt ist:

```text
mindestens 100 geschlossene Live-Trades
mindestens 12 Monate Laufzeit
mindestens 20 Fälle pro zentraler Outcome-/Exit-Klasse
vollständige Versionierung aktiv
Offline-Validierung schlägt naive Baselines
```

Statuswerte:

```text
locked
eligible_for_review
unlocked
```

Öffnet nicht automatisch. Finale Freigabe bleibt manuell.

---

## Kritischer Pfad

```text
1. v0.4 Truth Layer
   abgeschlossen

2. v0.5 Evidence Engine Lite
   abgeschlossen

3. v0.6 Signal Evidence Layer
   nächster Schritt

4. v0.7 Exit Engine & Portfolio Core

5. v0.8 Macro Layer

6. v0.9 LLM / Analyst Layer

7. v1.0 Decision Workbench
```

---

## Größte Risiken

```text
Evidence vor Truth bauen
Trade Evidence und Signal Evidence vermischen
eligible_outcome_only als eligible_full behandeln
Live / Paper / Pseudo vermischen
Hindsight-Snapshots zulassen
LLM-Text wichtiger machen als deterministische Zahlen
Makro hindsight-behaftet einspeisen
ML zu früh aktivieren
Durchschnittswerte ohne Streuung und Zeitfenster überschätzen
```

---

## Kurzfassung

```text
Jetzt:
v0.5 ist geschlossen.

Als Nächstes:
v0.6 Signal Evidence Layer starten.

Unverrückbare Regel:
Signal Evidence darf nur auf eligible_full basieren.
```
