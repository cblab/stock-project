# ROADMAP.md

## Nordstern

**stock-project v1.0** ist ein lokaler, erklärbarer Decision Assistant für Aktien, der Signale, Trade-History, Snapshot-Evidence, Makro-Regime und Portfolio-Risiko zu auditierbaren Kauf-, Halte-, Trim- und Exit-Entscheidungen verbindet.

Das System ist kein autonomer Trader.

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
   - Immer mit `n`, Return-Verteilung, Zeithorizont, Datenquelle und Evidenzstufe arbeiten.

3. **No fake certainty**
   - Kleine Fallzahlen erzeugen Hinweise, keine Regeln.

4. **Snapshot timing is law**
   - Kein Snapshot nach dem Event darf als damalige Wahrheit verwendet werden.

5. **Version everything**
   - `scoring_version`, `policy_version`, `model_version`, `macro_version` gehören in den Kontext.

6. **Live/Paper/Pseudo getrennt**
   - Keine stille Vermischung.

7. **Macro is a filter, not an oracle**

8. **LLMs explain, they do not decide**

9. **Read-only Evidence**
   - Evidence Engine liest den Truth Layer und mutiert keine Trade-Wahrheit.

---

## Aktueller Status

```text
v0.4 Truth Layer: completed
v0.5 Evidence Engine Lite: active
v0.6 Exit Engine & Portfolio Core: planned
```

Kanonische Architekturdateien:

```text
ARCHITECTURE_v04.md
ARCHITECTURE_v05.md
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

Status: **active**

Canonical architecture:

```text
ARCHITECTURE_v05.md
```

### Ziel

Read-only Evidence Engine, die aus vorhandenen Daten ehrlich zählt:

```text
1. Trade Outcome Evidence
2. Signal Forward-Return Evidence
```

### Nicht-Ziele

```text
kein ML
keine LLM-Entscheidung
keine Broker-Automation
keine Sizing-Automation
keine Write-Operationen auf Trade-Truth-Tabellen
keine automatische Live-Gewichtsänderung
UI nicht als Pflicht-Gate
```

### Grundregel

```text
Evidence Engine Lite darf nicht klug wirken.
Sie muss ehrlich zählen — und sichtbar machen, welche Zählung überhaupt zulässig ist.
```

### Evidence Sources

```text
trade_outcome
signal_forward_return
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

Die Evidence Engine bleibt quellenagnostisch. Eine Signalquelle darf ausgewertet werden, aber die Architektur darf nicht von einer einzelnen Quelle abhängen.

### Chunk Plan

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

### Parallelisierung

```text
🟢 C1 Evidence Read Models
  │
  ▼
⚪ C2 Trade Outcome Extractor
  │
  ├───────────────┐
  ▼               ▼
⚪ C6 Confidence Calculator     ⚪ C8 Validation Fixtures
```

### Confidence Standard

```text
n < 5      → anecdotal
5–19       → very_low
20–49      → low
50–99      → medium
100+       → high
```

Immer zeigen:

```text
n
avg_return
min_return
max_return
confidence_level
source
time_window
exclusion_summary
```

Ab `n >= 5` zusätzlich:

```text
standard_deviation
standard_error_of_mean
```

### Anti-Hindsight

Anti-Hindsight ist Teil von Extractor/Eligibility.

```text
snapshot.as_of_date <= event_timestamp
snapshot.instrument_id == trade.instrument_id
```

Wenn Kontext nicht validiert wird, darf er nicht still voll belastbare Evidence erzeugen.

### v0.5 Definition of Done

```text
Trade Evidence read-only
Signal Evidence read-only
Eligibility/Exclusion sichtbar
Anti-Hindsight früh
Confidence mit n und Streuung
Report Service + Command
Keine Mutation am Truth Layer
```

---

## v0.6 – Exit Engine & Portfolio Core

Status: **planned**

Ziel:

```text
v0.4 Truth Layer + v0.5 Evidence als Kontext für Exit-/Trim-/Hold-Entscheidungslogik nutzen.
```

Bauen:

```text
hold / watch_tightly / trim / hard_exit / pause
Positionsgrößen-Vorschlag
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

## v0.7 – Macro Layer

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

## v0.8 – LLM / Analyst Layer

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

## v0.9 – Decision Workbench & Validation

Status: **planned**

Bauen:

```text
Candidate → Thesis → Entry → Monitoring → Exit
Trade Journal
Evidence Panel
Sizing Panel
Macro Context
Robustness Checks
Live vs Paper vs Pseudo Vergleich
QuantStats-Reports
Exit-Reason-Analysen
```

---

## v1.0 – Closed Decision Loop / Decision Dashboard

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
   aktiv

3. v0.6 Exit Engine & Portfolio Core

4. v0.7 Macro Layer

5. v0.8 LLM / Analyst Layer

6. v0.9 Decision Workbench & Validation

7. v1.0 Closed Decision Loop
```

---

## Größte Risiken

```text
Evidence vor Truth bauen
Trade Evidence und Signal Evidence vermischen
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
v0.5 C2 fertigstellen

Morgen:
C6 Confidence Calculator und C8 Validation Fixtures parallel

Danach:
Eligibility, Signal Evidence, Aggregatoren, Report
```
