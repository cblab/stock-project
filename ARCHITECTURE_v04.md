# ARCHITECTURE v0.4 – Truth Layer

stock-project · v0.4 · finaler Architekturstand  
Status: **abgeschlossen**  
Stand: **2026-04-27**

Dieses Dokument beschreibt den abgeschlossenen v0.4 Truth Layer. Neue Evidence-Engine-Planung gehört nach `ARCHITECTURE_v05.md`.

## Leitprinzip

v0.4 baut kein Scoring, keine Evidence Engine, kein ML und keinen Advisor.

v0.4 baut das belastbare, versionierte Gedächtnis für Trades:

```text
Entry → Campaign → Events → Exit/Return → P&L → History
```

Kernsatz:

```text
Kein Portfolio-State ohne Trade-Event.
Kein Exit ohne Trade-Event.
Kein Return-to-Watchlist ohne Trade-Event.
```

---

## 1. Ziel

Das System speichert jede neue Trade-Entscheidung zeitlich und fachlich nachvollziehbar.

Gespeichert wird:

```text
was passierte
wann es passierte
zu welchem Preis
mit welcher Menge
ob live, paper oder pseudo
mit welchem Snapshot-Kontext
mit welcher Version
warum Exit/Trim/Return passierte
welches Ergebnis daraus entstand
```

Damit wird v0.5 möglich:

```text
Evidence Engine: Was hat in ähnlichen Situationen historisch funktioniert?
```

---

## 2. Nicht-Ziele

```text
keine Evidence Engine
kein ML
kein LLM
keine automatische Kauf-/Verkaufsentscheidung
kein Redis
keine Message Queue für Trade-Writes
kein Macro-Layer
keine Broker-Integration
keine automatische Bestandsrekonstruktion alter Trades
```

---

## 3. Zentrale Architekturentscheidung

Der Truth Layer besteht aus:

```text
trade_campaign
trade_event
trade_migration_log
```

### trade_campaign

Eine Campaign ist ein vollständiger Trade-Zyklus pro Instrument.

Beispiel:

```text
INSTRUMENT_A Entry → Add → Trim → Hard Exit
```

### trade_event

Ein Event ist eine einzelne Aktion innerhalb einer Campaign:

```text
entry
add
trim
pause
resume
hard_exit
return_to_watchlist
migration_seed
```

### trade_migration_log

Dokumentiert, wie Altpositionen in den Truth Layer überführt wurden.

---

## 4. Datenmodell

### trade_campaign

Konzeptionelle Felder:

```text
id
instrument_id
trade_type: live | paper | pseudo
state
entry_thesis
invalidation_rule
total_quantity
open_quantity
avg_entry_price
realized_pnl_gross
realized_pnl_net
tax_rate_applied
realized_pnl_pct
opened_at
closed_at
entry_macro_snapshot_id nullable
exit_macro_snapshot_id nullable
created_at
updated_at
```

States:

```text
open
trimmed
paused
closed_profit
closed_loss
closed_neutral
returned_to_watchlist
```

### trade_event

Konzeptionelle Felder:

```text
id
trade_campaign_id
instrument_id
event_type
exit_reason nullable
event_price nullable
quantity nullable
fees
currency
event_timestamp
buy_signal_snapshot_id nullable
sepa_snapshot_id nullable
epa_snapshot_id nullable
macro_snapshot_id nullable
scoring_version
policy_version
model_version
macro_version
event_notes
created_at
```

Exit Reasons:

```text
signal
stop_loss
trailing_stop
time_based
rebalance
opportunity_cost
macro_regime_change
thesis_invalidated
manual
```

### trade_migration_log

```text
id
instrument_id
trade_campaign_id
migration_status: full | partial | manual_seed
migration_notes
migrated_at
```

---

## 5. Snapshot-Timing-Policy

Grundregel:

```text
Der verknüpfte Snapshot ist der letzte abgeschlossene Snapshot vor dem Event.
```

Snapshot-Datumsfeld:

```text
as_of_date
```

Lookup-Regel:

```sql
SELECT id
FROM instrument_sepa_snapshot
WHERE instrument_id = :instrument_id
  AND as_of_date < DATE(:event_timestamp)
ORDER BY as_of_date DESC
LIMIT 1;
```

Gilt analog für:

```text
instrument_buy_signal_snapshot
instrument_sepa_snapshot
instrument_epa_snapshot
```

Wichtig:

```text
Snapshot vom gleichen Kalendertag zählt nicht.
Kein Snapshot gefunden => NULL.
NULL ist kein Fehler, sondern "no context".
```

Legacy Seed Sonderregel:

```text
Wenn opened_at vor Snapshot-Datum liegt, wird keine Snapshot-ID gesetzt.
Lieber NULL als falsche historische Wahrheit.
```

---

## 6. Versionierung

Versionsstrings gehören auf jedes `trade_event`.

```text
scoring_version
policy_version
model_version
macro_version
```

Zentrale Config:

```text
config/system_versions.json
```

---

## 7. TradeEventWriter

Trade-Writes müssen synchron und atomar sein:

```text
BEGIN
  trade_campaign schreiben/aktualisieren
  trade_event schreiben
  instrument state aktualisieren
COMMIT
```

Kernsatz:

```text
TradeEventWriter ist die normale Schreibstelle für Portfolio-Trade-Zustände.
```

---

## 8. Full-Exit-Regeln

```text
hard_exit:
  trade_campaign.state = closed_profit | closed_loss | closed_neutral
  trade_campaign.open_quantity = 0
  instrument.is_portfolio = 0
  instrument.active = 1

return_to_watchlist:
  trade_campaign.state = returned_to_watchlist
  trade_campaign.open_quantity = 0
  instrument.is_portfolio = 0
  instrument.active = 1

trim:
  trade_campaign.state = trimmed
  trade_campaign.open_quantity > 0
  instrument.is_portfolio bleibt 1
  instrument.active bleibt 1
```

---

## 9. P&L-Konventionen

```text
realized_pnl_gross = Geldbetrag
realized_pnl_net   = Geldbetrag
realized_pnl_pct   = Ratio
```

Ratio:

```text
0.30 = 30 %
-0.12 = -12 %
```

---

## 10. Finaler Stand

Abgeschlossen:

```text
Schema Foundation
Version Config
Snapshot Resolver
State Machine + Domain Validation
P&L Calculator
TradeEventWriter
Entry UI
Exit / Trim / Pause / Return UI
Minimal Audit View
Legacy Integrity / Seed Plan / Template / Validator
Legacy SQL Seed
T1 Decimal/Money Safety Audit
T2 Campaign-Level realized_pnl_pct
T2c Portfolio Flag After Full Exit
```

Legacy Seed:

```text
21 bestehende Positionen als migration_seed migriert.
Snapshot-IDs bei Hindsight-Risiko NULL.
```

---

## 11. Bekannte technische Schuld

### Float-Arithmetik

DB-Spalten nutzen `DECIMAL`, Services verwenden intern teilweise PHP `float`.

Bewertung:

```text
Für v0.4 akzeptabel.
Für v0.5 kein Blocker.
Für spätere exakte Money-/Broker-/Tax-Logik separat prüfen.
```

### Concurrency

`SELECT FOR UPDATE` / Unique Constraints für offene Campaigns bleiben spätere technische Schuld.

---

## 12. Abschlussnotiz

```text
v0.4 baut nicht Intelligenz.
v0.4 baut Wahrheit.
```

Status:

```text
v0.4 Truth Layer ist fachlich und technisch abgeschlossen.
```

Nächster Architekturvertrag:

```text
ARCHITECTURE_v05.md
```
