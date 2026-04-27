# ROADMAP.md

## Nordstern

**stock-project v1.0** ist ein lokaler, erklärbarer Decision Assistant für Aktien, der **Signale, echte/paper/pseudo Trade-History, Snapshot-Evidence, Makro-Regime und Portfolio-Risiko** zu auditierbaren Kauf-, Halte-, Trim- und Exit-Entscheidungen verbindet.

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
   - Jede Aggregation zeigt ihre Fallzahl und Confidence.

4. **Snapshot timing is law**
   - Jeder Trade-Event referenziert den letzten abgeschlossenen Snapshot vor dem Event-Timestamp.
   - Kein Snapshot nach dem Event darf als damalige Wahrheit verwendet werden.

5. **Version everything**
   - `scoring_version`, `policy_version`, `model_version`, `macro_version` gehören ins Schema oder in den Report-Kontext.

6. **Live/Paper/Pseudo getrennt**
   - Diese Datenquellen laufen über `trade_type`.
   - Sie dürfen nie still vermischt werden.

7. **Macro is a filter, not an oracle**
   - Makro steuert Kontext und Schwellen, ersetzt aber keine Titelselektion.

8. **LLMs explain, they do not decide**
   - Rechenlogik deterministisch, LLM nur für sprachliche Interpretation.

9. **Read-only Evidence**
   - Evidence Engine liest den Truth Layer.
   - Sie mutiert keine Trade-Wahrheit.

---

## Aktueller Status

```text
v0.4 Truth Layer: abgeschlossen
v0.5 Evidence Engine Lite: next / active planning
v0.6 Exit Engine & Portfolio Core: planned
```

Kanonische Architekturdateien:

```text
ARCHITECTURE_v04.md
ARCHITECTURE_v05.md
```

---

## v0.4 – Truth Layer

Status: **completed**

### Ziel

Das System besitzt ein belastbares Gedächtnis für echte und virtuelle Trades.

### Gebaut

- `trade_campaign`
- `trade_event`
- `trade_migration_log`
- `trade_type = live | paper | pseudo`
- State Machine:
  - `open`
  - `trimmed`
  - `paused`
  - `closed_profit`
  - `closed_loss`
  - `closed_neutral`
  - `returned_to_watchlist`
- Event Types:
  - `entry`
  - `add`
  - `trim`
  - `pause`
  - `resume`
  - `hard_exit`
  - `return_to_watchlist`
  - `migration_seed`
- Exit-Reason-Taxonomie
- `TradeVersionProvider`
- `TradeSnapshotResolver`
- `TradeStateMachine`
- `TradeEventValidator`
- `TradePnlCalculator`
- `TradeEventWriter`
- `TradeEventWriteResult`
- `macro_snapshot_id` / `entry_macro_snapshot_id` / `exit_macro_snapshot_id` vorbereitet, aber v0.4 NULL

### Abgeschlossene Nacharbeiten

```text
T1 Decimal/Money Safety Audit
T2 Campaign-Level realized_pnl_pct verifiziert
T2b Test-Payload-Fix für exit_reason
T2c Portfolio Flag After Full Exit
Legacy SQL Seed: 21 Positionen migriert
Altzustand sicher repariert
```

Finaler lokaler Teststand:

```text
TradeEventWriterIntegrationTest
OK (8 tests, 32 assertions)
```

### Wichtige finale Entscheidungen

- `realized_pnl_pct` ist Ratio:
  - `0.30 = 30 %`
- Full Exit entfernt Instrument aus Portfolio:
  - `hard_exit` → `instrument.is_portfolio = 0`, `active = 1`
  - `return_to_watchlist` → `instrument.is_portfolio = 0`, `active = 1`
  - `trim` lässt `instrument.is_portfolio = 1`
- Legacy-Seed-Snapshot-IDs wurden bewusst `NULL` gelassen, wenn sie Hindsight-Bias erzeugt hätten.
- Float-Arithmetik bleibt bekannte technische Schuld, aber kein Blocker für v0.5.

Canonical architecture:

```text
ARCHITECTURE_v04.md
```

---

## v0.5 – Evidence Engine Lite

Status: **next / active planning**

Canonical architecture:

```text
ARCHITECTURE_v05.md
```

### Ziel

Read-only Evidence Engine, die aus vorhandenen Daten ehrlich zählt:

```text
1. Trade Outcome Evidence
2. Snapshot Forward-Return Evidence
```

### Nicht-Ziele

- kein ML
- keine LLM-Entscheidung
- keine Broker-Automation
- keine Sizing-Automation
- keine Write-Operationen auf Trade-Truth-Tabellen
- keine automatische Live-Gewichtsänderung
- UI nicht als Pflicht-Gate

### Grundregel

```text
Evidence Engine Lite darf nicht klug wirken.
Sie muss ehrlich zählen — und sichtbar machen, welche Zählung überhaupt zulässig ist.
```

### Evidence Sources

```text
trade_outcome
snapshot_forward_return
```

Trade Evidence beantwortet:

```text
Wie gut waren meine echten/paper/pseudo Entscheidungen?
Welche Entry-/Exit-Pfade haben funktioniert?
Welche Exit Reasons waren teuer oder nützlich?
```

Snapshot Evidence beantwortet:

```text
Wie verhielten sich SEPA/EPA/Buy-Signal-Zustände danach am Markt?
Hatten Score-Buckets bessere Forward Returns?
```

### Chunk Plan

```text
C1  Evidence Read Models
C2  Closed Trade Outcome Extractor
C3  Eligibility + Anti-Hindsight Core
C6  Confidence Calculator
C8  Validation Fixtures / Poison Pills
C2b SEPA Snapshot Evidence Extractor
C4  Entry Evidence Aggregator
C5  Exit Evidence Aggregator
C7  Evidence Report Service + Command
C10 Evidence Audit Gate
C9  Minimal UI optional
```

### Risikogewichte

```text
C1  sehr wichtig
C2  extrem
C3  extrem
C6  sehr wichtig
C8  sehr wichtig
C2b sehr wichtig
C4  extrem
C5  extrem
C7  wichtig
C10 extrem
C9  optional / wichtig
```

### Parallelisierung

```text
🟢 v0.4 abgeschlossen
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
🟢 v0.5 abgeschlossen

Optional:
⚪ C9 Minimal UI
```

### Confidence Standard

Basis:

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

Anti-Hindsight ist Teil von C2/C3, nicht erst ein spätes Report-Gate.

```text
snapshot.as_of_date <= event_timestamp
snapshot.instrument_id == trade.instrument_id
missing/hindsight-risk snapshots führen zu Exclusion oder eingeschränkter Eligibility
```

C10 prüft nur noch, ob der Guard überall greift.

### v0.5 Definition of Done

- Trade Evidence läuft read-only.
- Snapshot Evidence läuft read-only.
- Eligibility/Exclusion ist sichtbar.
- Anti-Hindsight-Regeln greifen früh.
- Confidence zeigt `n`, Streuung und Warnflags.
- Report Service + Command erzeugen nachvollziehbare Ausgabe.
- Kein UI-Zwang.
- Keine Mutation am Truth Layer.

---

## v0.6 – Exit Engine & Portfolio Core

Status: **planned**

### Ziel

Vom Signal zur Kapitalentscheidung. Nutzt v0.4 Truth Layer und v0.5 Evidence als Kontext für Exit-/Trim-/Hold-Entscheidungslogik.

### Bauen

- Exit-Aktionen: `hold`, `watch_tightly`, `trim`, `hard_exit`, `pause`
- Exit-Reason-Taxonomie in echter Nutzung
- Positionsgrößen-Vorschlag
- Konzentrationswarnungen
- Cluster-/Korrelationswarnungen
- Add/Trim-Rebalancing-Logik
- Länderverteilung
- Sektorverteilung
- Cash-Anteil
- Daily Monitoring View / Open Position Monitor

### Nicht enthalten

- Auto-Trading
- Broker-Anbindung
- automatische Verkäufe

### Portfolio-Bremse

Bei Depot-/Portfolio-Daten prüfen:

```text
Spezifischer-Cluster > 50 %
  → Trimming und Umschichtung in stabilisierende, niedriger korrelierte Assets ansprechen.

Einzelposition > 10 %
  → Trimming ansprechen.
  Ausnahme: breite Welt-ETFs wie MSCI World/IWDA.

Position +100 %
  → 20–30 % Trim vorschlagen.
```

### Definition of done

- Nutzer bekommt strukturierte Hold/Trim/Exit-Hinweise.
- Portfolio-Risiken werden sichtbar.
- Offene Positionen sind im Monitoring View dargestellt.
- Evidence wird als Signal behandelt, nicht als harter Trigger.

---

## v0.7 – Macro Layer

Status: **planned**

### Ziel

Regimekontext sauber anschließen.

### Bauen

- `macro_snapshot`
- Last-known-available Policy
- VIX
- Yield Curve / Term Spread
- CPI YoY
- PMI / ISM
- Sector Rotation Relative Strength
- Regime-Labels
- FK und Befüllung für `macro_snapshot_id` / `entry_macro_snapshot_id` / `exit_macro_snapshot_id`

### Regeln

- Makro nur mit zum Trade-Zeitpunkt verfügbaren Daten.
- Kein retrospektives Schummeln mit später veröffentlichten Werten.
- Macro-Kontext darf keine Hindsight-Daten nutzen.

### Definition of done

- Makro-Regime ist pro Trade/Event referenzierbar.
- Evidence Engine kann optional nach Regime konditionieren.
- Sizing / Schwellen können durch Makro gedämpft werden.

---

## v0.8 – LLM / Analyst Layer

Status: **planned**

### Ziel

Das System erklärt und coacht.

### Bauen

- Daily Briefing
- Portfolio-Coach
- Konflikt-/Agreement-Erklärungen
- LLM nur für sprachliche Interpretation
- Policy-Profile als optionale Advisor-Profile:
  - Buffett
  - Dalio
  - Lynch

### Klare Regel

- Zahlen und Regeln bleiben deterministisch.
- LLM erklärt.
- LLM prüft Widersprüche.
- LLM entwirft Thesen und Invalidation Rules als Vorschlag.
- Nutzer muss prüfen, ändern und bestätigen.
- LLM entscheidet nicht numerisch.

---

## v0.9 – Decision Workbench & Validation

Status: **planned**

### Ziel

Bevor dem System vertraut wird, muss es sich selbst prüfen.

### Bauen

- Candidate → Thesis → Entry → Monitoring → Exit
- Trade Journal
- Evidence Panel
- Sizing Panel
- Macro Context
- Robustness Checks
- Walk-forward/Stabilität
- Live vs Paper vs Pseudo Vergleich
- QuantStats-Reports
- Alignment-Validierung
- Exit-Reason-Analysen
- Optionale spätere vectorbt-Validierung

### Definition of done

- Mindestens ein reproduzierbarer Evidence-/Performance-Report existiert.
- Live/Paper/Pseudo werden getrennt ausgewertet.
- Exit-Reason-Analysen sind sichtbar.
- Regressions in Score-/Policy-Logik werden erkennbar.
- Für jeden abgeschlossenen Trade kann der vollständige Entscheidungsweg rekonstruiert werden.

---

## v1.0 – Closed Decision Loop / Decision Dashboard

Status: **target**

### Ziel

Ein vollständiger, ehrlicher Decision Assistant.

### Muss enthalten

- Entry-Decision
- Hold-Decision
- Trim-Decision
- Exit-Decision
- Portfolio Coach
- Macro Overlay
- Evidence Engine
- Klare Unsicherheitskommunikation

### Definition

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

### Nicht Teil von v1.0

- autonomer Handel
- Broker-Anbindung
- RL / FinRL
- automatische Live-Gewichtsänderung
- LLM als numerischer Entscheider

---

## Self-Learning Gate

Self-Learning bleibt **deaktiviert**, bis gleichzeitig erfüllt ist:

- mindestens **100 geschlossene Live-Trades**
- mindestens **12 Monate Laufzeit**
- mindestens **20 Fälle** pro zentraler Outcome-/Exit-Klasse
- vollständige Versionierung aktiv
- Offline-Validierung schlägt naive Baselines

### Geplanter v0.9-Job: `check_self_learning_gate.py`

Statuswerte:

```text
locked
eligible_for_review
unlocked
```

Öffnet Self-Learning nicht automatisch. Finale Freigabe bleibt manuell.

Vorher gilt:

```text
Evidence Engine only.
Keine automatischen Gewichtsänderungen.
```

---

## Kritischer Pfad

```text
1. v0.4 Truth Layer
   abgeschlossen

2. v0.5 Evidence Engine Lite
   nächste aktive Planung

3. v0.6 Exit Engine & Portfolio Core
   nutzt v0.4 + v0.5

4. v0.7 Macro Layer
   ergänzt Regime-Kontext

5. v0.8 LLM / Analyst Layer
   erklärt, entscheidet nicht

6. v0.9 Decision Workbench & Validation
   validiert das Gesamtsystem

7. v1.0 Closed Decision Loop
   vollständiger Decision Assistant
```

---

## Größte Risiken

1. Evidence vor Truth bauen
2. Trade Evidence und Snapshot Evidence vermischen
3. Live / Paper / Pseudo vermischen
4. Hindsight-Snapshots zulassen
5. LLM-Text wichtiger machen als Zahlen
6. Makro hindsight-behaftet einspeisen
7. ML zu früh aktivieren
8. Durchschnittswerte ohne Streuung und Zeitfenster überschätzen

---

## Entscheidungen, die jetzt feststehen

- v0.4 ist abgeschlossen.
- v0.5 startet als read-only Evidence Engine Lite.
- v0.5 nutzt Trade Evidence und Snapshot Evidence.
- Anti-Hindsight gehört in Extractor/Eligibility, nicht ans Ende.
- UI ist nicht v0.5-Gate.
- `trade_type` bleibt Trennlinie für live/paper/pseudo.
- `macro_snapshot_id` bleibt vorbereitet, wird aber erst v0.7 befüllt.
- Self-Learning bleibt locked.

---

## Kurzfassung

```text
Jetzt:
v0.5 Evidence Engine Lite bauen

Danach:
v0.6 Exit Engine & Portfolio Core

Dann:
Makro, Analyst Layer, Workbench

Erst ganz am Ende:
Self-Learning mit harten Gates
```
