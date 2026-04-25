# ROADMAP.md

## Nordstern

**stock-project v1.0** ist ein lokaler, erklärbarer Decision Assistant für Aktien, der **Signale, Trade-History, Makro-Regime und Portfolio-Risiko** zu evidenzbasierten Kauf-, Halte-, Trim- und Exit-Entscheidungen verbindet.

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
   - Jeder Trade referenziert den **letzten abgeschlossenen Snapshot vor dem Event-Timestamp**.

5. **Version everything**
   - `scoring_version`, `policy_version`, `model_version`, `macro_version` gehören ins Schema.

6. **Live, Paper, Pseudo getrennt**
   - Diese Datenquellen dürfen nie still vermischt werden.

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
- Entry-/Exit-Referenzen auf Snapshots
- `snapshot_timestamp`
- `paper_trade`-Flag
- `macro_snapshot_id` zunächst nullable
- `scoring_version`, `policy_version`, `model_version`, `macro_version`
- Exit-Reason-Taxonomie
- Brutto-/Netto-P&L-Felder
- Thesis Journal (`entry_thesis`, `invalidation_rule`)
- Migration bestehender Portfolio-Positionen mit dokumentierten Lücken

### Wichtige Entscheidungen
- **Schema:** `trade_campaign` + `trade_event`, nicht nur Felder am Instrument
- **Paper-Trading:** jetzt als Modus vorsehen
- **Snapshot-Regel:** letzter abgeschlossener Snapshot vor Event-Zeit

### Definition of done
- Watchlist → Portfolio erzeugt eine `trade_campaign` + erstes `trade_event`
- Verkauf / Trim / Pause erzeugt weitere `trade_event`-Zeilen
- bestehende Portfolio-Positionen sind migriert
- Snapshot-Referenzen und Zeitstempel sind validierbar

---

## v0.5 – Evidence Engine Lite

### Ziel
Ehrliche Evidenz ohne ML.

### Bauen
- Similarity-/Bucket-Lookup
- Evidenzstufen via `n`
- getrennte Auswertung für `live`, `paper`, `pseudo`
- Entry-Evidence
- Exit-Evidence
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

### Definition of done
- das System kann für eine Entry-/Exit-Konstellation ähnliche Fälle finden
- bei kleinem `n` wird keine falsche Wahrscheinlichkeit ausgegeben
- Alignment-State wird in Evidenzberichten nutzbar

---

## v0.6 – Exit & Portfolio Core

### Ziel
Vom Signal zur Kapitalentscheidung.

### Bauen
- Exit-Aktionen: `hold`, `watch_tightly`, `trim`, `hard_exit`, `pause`
- Exit-Reason-Taxonomie in echter Nutzung
- erste Sizing-Engine
- Kaufmengen-Vorschlag
- Länderverteilung
- Sektorverteilung
- Cash-Anteil
- Konzentrationswarnungen
- Cluster-/Korrelationswarnungen grob

### Sizing-Logik
- Basis-Risikobudget
- Volatilitätsanpassung
- Konzentrationskappe
- optional konservatives Fractional Kelly als Obergrenze

### Definition of done
- der Nutzer bekommt beim Kauf eine Größenempfehlung
- der Nutzer bekommt beim Verkauf/Trimmen einen strukturierten Exit-Vorschlag
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

### Regeln
- Makro nur mit zum Trade-Zeitpunkt verfügbaren Daten
- kein retrospektives Schummeln mit später veröffentlichten Werten

### Definition of done
- Makro-Regime ist pro Trade/Event referenzierbar
- Evidence Engine kann optional nach Regime konditionieren
- Sizing / Schwellen können durch Makro gedämpft werden

---

## v0.8 – Persona & Advisor Layer

### Ziel
Das System erklärt und coacht.

### Bauen
- Policy-Profile:
  - **Buffett**
  - **Dalio**
  - **Lynch**
- Daily Briefing
- Portfolio-Coach
- Konflikt-/Agreement-Erklärungen
- LLM nur für sprachliche Interpretation

### Klare Regel
- Zahlen und Regeln bleiben deterministisch
- LLM ist **Interpretation**, nicht **Entscheidung**

### Definition of done
- Nutzer sieht persona-basierte Perspektiven auf dieselbe Situation
- Daily Briefing fasst die wichtigsten Handlungsfälle zusammen
- Advisor-Texte sind nachvollziehbar und an Zahlen gebunden

---

## v0.9 – Robustness & Validation

### Ziel
Bevor dem System vertraut wird, muss es sich selbst prüfen.

### Bauen
- QuantStats-Reports
- Alignment-Validierung
- Exit-Reason-Analysen
- Vergleich `live` vs. `paper` vs. `pseudo`
- Walk-forward-/Stabilitätsprüfungen
- optionale spätere vectorbt-Validierung

### Definition of done
- das System kann seine eigene Evidenzqualität prüfen
- Schwächen einzelner Policies oder Exit-Klassen werden sichtbar
- keine stillen Regressions in Score- oder Policy-Logik

---

## v1.0 – Decision Dashboard

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
- klare Unsicherheitskommunikation

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

Vorher gilt:
- **Evidence Engine only**
- keine automatischen Gewichtsänderungen

---

## Kritischer Pfad

1. **v0.4 Truth Layer**
   - ohne ihn ist alles danach wertlos

2. **v0.5 Evidence Engine**
   - ohne ehrliche Evidenz lügt das System über seine Qualität

3. **v0.6 Exit & Portfolio Core**
   - ohne saubere Exit- und Positionslogik fehlt der eigentliche Nutzwert

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
- `paper_trade`-Pfad wird früh vorgesehen
- `macro_snapshot_id` kommt früh ins Schema, auch wenn Makro später befüllt wird

---

## Kurzfassung

### Jetzt
- Truth Layer sauber designen und umsetzen

### Danach
- ehrliche Evidence Engine ohne ML

### Dann
- Exit + Portfolio + Makro + Advisor

### Erst ganz am Ende
- Self-Learning, nur mit harten Gates