# ROADMAP.md

## Nordstern

**stock-project v1.0** ist ein lokaler, erklärbarer Decision Assistant für Aktien, der **Signale, echte/paper/pseudo Trade-History, Makro-Regime und Portfolio-Risiko** zu auditierbaren Kauf-, Halte-, Trim- und Exit-Entscheidungen verbindet.

## Leitprinzipien

1. **Truth before intelligence**
   - Erst echte Trade-History, dann Evidenz, dann ML.

2. **Evidence before probability**
   - Immer mit `n`, Median-Return, Zeithorizont und Evidenzstufe arbeiten.

3. **No fake certainty**
   - `n < 20` ⇒ **Insufficient Data**
   - `20-49` ⇒ **Low Evidence**
   - `50-99` ⇒ **Medium Evidence**
   - `100+` ⇒ **High Evidence**

4. **Snapshot timing is law**
   - Jeder Trade referenziert konkrete Snapshot-IDs:
     - `buy_signal_snapshot_id`
     - `sepa_snapshot_id`
     - `epa_snapshot_id`
     - später `macro_snapshot_id`

5. **Version everything**
   - `scoring_version`, `policy_version`, `model_version`, `macro_version` gehören ins Schema.

6. **Live/Paper/Pseudo getrennt**
   - Diese Datenquellen laufen über `trade_type`, nicht über `paper_trade`.
   - Sie dürfen nie still vermischt werden.

7. **Macro is a filter, not an oracle**
   - Makro steuert Schwellen und Sizing, ersetzt aber keine Titelselektion.

8. **LLMs explain, they do not decide**
   - Rechenlogik deterministisch, LLM nur für sprachliche Interpretation.

---

## v0.4 – Truth Layer

### Ziel
Das System bekommt ein belastbares Gedächtnis für echte und virtuelle Trades.

### Bauen
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

### Nicht in v0.4
- Evidence Engine
- Sizing
- Macro Layer
- LLM
- Redis / Queue / Async

### Wichtige Entscheidungen
- **Schema:** `trade_campaign` + `trade_event`, nicht nur Felder am Instrument
- **Paper-Trading:** `trade_type` als Modus vorgesehen
- **Snapshot-Regel:** Referenz auf konkrete Snapshot-IDs, nicht nur Timestamp
- **Migration:** Bestehende Positionen können später über `migration_seed`/`manual_seed` migriert werden; sie sind nicht Teil des initialen Truth-Layer-Write-Paths

### Definition of done
- Watchlist → Portfolio erzeugt eine `trade_campaign` + erstes `trade_event`
- Verkauf / Trim / Pause / Return erzeugt weitere `trade_event`-Zeilen
- Snapshot-Referenzen und Zeitstempel sind validierbar
- State Machine validiert erlaubte Transitionen

### Aktueller Chunk-Stand

1. Schema Foundation ✅
2. Version Config ✅
3. Snapshot Resolver ✅
4. State Machine + Domain Validation ✅
5. P&L Calculator ✅
6. TradeEventWriter ✅
7. Entry UI ⏭️
8. Exit / Trim / Pause / Return UI ⏭️
9. Trade History + Campaign Detail ⏭️
10. Legacy Migration + Recalculator ⏭️

### Parallelisierung

- Chunk 1 alleine
- Chunk 2 + 3 parallel
- Chunk 4 + 5 parallel
- Chunk 6 alleine
- Chunk 7 + 8 parallel nach Chunk 6
- Chunk 9 + 10 danach parallel möglich, aber Chunk 9 wichtiger für Benutzbarkeit

---

## v0.5 – Evidence Engine Lite

### Ziel
Ehrliche Evidenz ohne ML, basierend auf abgeschlossenen `trade_campaign`/`trade_event`-Daten.

### Bauen
- Similarity-/Bucket-Lookup
- Evidenzstufen via `n`
- `manual_seed`/`migration_seed` getrennt behandeln
- Live/Paper/Pseudo nie in einer Aggregationszeile vermischen
- Entry-Evidence und Exit-Evidence aus Snapshot-Konstellationen
- Alignment-Evidence (SEPA vs. K/S/M)
- UI-Zustand **Insufficient Data**

### Output-Standard
Immer zeigen:
- `n`
- Trefferquote
- Median-Return
- Zielhorizont
- Datenquelle
- Evidenzstufe

### Wichtige Regeln
- Keine Wahrscheinlichkeit ohne `n`/Evidenzstufe
- Kein ML

### Definition of done
- Das System kann für eine Entry-/Exit-Konstellation ähnliche Fälle finden
- Bei kleinem `n` wird keine falsche Wahrscheinlichkeit ausgegeben
- Alignment-State wird in Evidenzberichten nutzbar

### Technical Debt before v0.5

- **Decimal/Money-safe arithmetic prüfen**
- v0.4 verwendet `float` pragmatisch in Calculator/Writer
- DB-Wahrheit bleibt DECIMAL
- Vor Evidence Engine soll `TradePnlCalculator` auf decimal-safe string-in/string-out oder BigDecimal/BCMath/brick/math vorbereitet werden
- **Ziel:** Keine Float-Aggregation als Grundlage für Evidence/Sizing

---

## v0.6 – Sizing & Portfolio Core

### Ziel
Vom Signal zur Kapitalentscheidung.

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

### Nicht enthalten
- Auto-Trading

### Sizing-Logik (später)
- Basis-Risikobudget
- Volatilitätsanpassung
- Konzentrationskappe
- Optional konservatives Fractional Kelly als Obergrenze

### Definition of done
- Der Nutzer bekommt beim Kauf eine Größenempfehlung
- Der Nutzer bekommt beim Verkauf/Trimmen einen strukturierten Exit-Vorschlag
- Portfolio-Risiken werden sichtbar

---

## v0.7 – Macro Layer

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

### Verbindung zu v0.4
- `macro_snapshot_id` ist in v0.4 vorbereitet (Schema-Felder existieren)
- FK und Befüllung kommen erst v0.7

### Regeln
- Makro nur mit zum Trade-Zeitpunkt verfügbaren Daten
- Kein retrospektives Schummeln mit später veröffentlichten Werten
- Macro-Kontext darf keine Hindsight-Daten nutzen

### Definition of done
- Makro-Regime ist pro Trade/Event referenzierbar
- Evidence Engine kann optional nach Regime konditionieren
- Sizing / Schwellen können durch Makro gedämpft werden

---

## v0.8 – LLM / Analyst Layer

### Ziel
Das System erklärt und coacht.

### Bauen
- Daily Briefing
- Portfolio-Coach
- Konflikt-/Agreement-Erklärungen
- LLM nur für sprachliche Interpretation
- Policy-Profile als optionale Advisor-Profile:
  - **Buffett**
  - **Dalio**
  - **Lynch**

### Klare Regel
- Zahlen und Regeln bleiben deterministisch
- LLM erklärt
- LLM prüft Widersprüche
- LLM schreibt Thesen/Invalidation Rules vor
- LLM entscheidet nicht numerisch

### Definition of done
- Nutzer sieht persona-basierte Perspektiven auf dieselbe Situation
- Daily Briefing fasst die wichtigsten Handlungsfälle zusammen
- Advisor-Texte sind nachvollziehbar und an Zahlen gebunden

---

## v0.9 – Decision Workbench & Validation

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
- Das System kann seine eigene Evidenzqualität prüfen
- Schwächen einzelner Policies oder Exit-Klassen werden sichtbar
- Keine stillen Regressions in Score- oder Policy-Logik

---

## v1.0 – Closed Decision Loop / Decision Dashboard

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
```
Research
→ Candidate
→ Thesis
→ Entry
→ Monitoring
→ Hold/Trim/Exit/Return
→ Outcome
→ Evidence Learning
→ Bessere nächste Entscheidung
```

### Nicht Teil von v1.0
- Autonomer Handel
- Broker-Anbindung
- RL / FinRL
- Automatische Live-Gewichtsänderung
- LLM als numerischer Entscheider

---

## Self-Learning Gate

Self-Learning bleibt **deaktiviert**, bis gleichzeitig erfüllt ist:

- Mindestens **100 geschlossene Live-Trades**
- Mindestens **12 Monate Laufzeit**
- Mindestens **20 Fälle** pro zentraler Outcome-/Exit-Klasse
- Vollständige Versionierung aktiv
- Offline-Validierung schlägt naive Baselines

Vorher gilt:
- **Evidence Engine only**
- Keine automatischen Gewichtsänderungen

---

## Kritischer Pfad

1. **v0.4 Truth Layer**
   - Ohne ihn ist alles danach wertlos

2. **v0.5 Evidence Engine**
   - Ohne ehrliche Evidenz lügt das System über seine Qualität

3. **v0.6 Sizing & Portfolio Core**
   - Ohne saubere Exit- und Positionslogik fehlt der eigentliche Nutzwert

---

## Größte Risiken

1. Evidence vor Truth bauen
2. Live / Paper / Pseudo vermischen
3. LLM-Text wichtiger machen als Zahlen
4. Makro hindsight-behaftet einspeisen
5. ML zu früh aktivieren

---

## Entscheidungen, die jetzt feststehen

- Trade-History zuerst
- Wahrscheinlichkeiten danach
- ML ganz am Ende
- Personas regelbasiert + LLM nur zur Erklärung
- `trade_type`-Pfad wird früh vorgesehen (live/paper/pseudo)
- `macro_snapshot_id` kommt früh ins Schema, auch wenn Makro später befüllt wird

---

## Kurzfassung

### Jetzt
- Truth Layer sauber designen und umsetzen

### Danach
- Ehrliche Evidence Engine ohne ML

### Dann
- Sizing & Portfolio + Makro + Advisor

### Erst ganz am Ende
- Self-Learning, nur mit harten Gates