# ARCHITECTURE v0.4 – Truth Layer

stock-project · v0.4 · finaler Architekturstand  
Status: **abgeschlossen**  
Stand: **2026-04-27**

Dieses Dokument beschreibt den abgeschlossenen v0.4 Truth Layer. Neue Evidence-Engine-Planung gehört nach `ARCHITECTURE_v05.md`, nicht in dieses Dokument.

## Leitprinzip

v0.4 baut kein Scoring, keine Evidence Engine, kein ML und keinen Advisor.

v0.4 baut das belastbare, versionierte Gedächtnis für Trades:

```text
Entry → Campaign → Events → Exit/Return → P&L → History
```

Der zentrale Satz:

```text
Kein Portfolio-State ohne Trade-Event.
Kein Exit ohne Trade-Event.
Kein Return-to-Watchlist ohne Trade-Event.
```

v0.4 ist der Teil, der verhindert, dass spätere Auswertungen auf nachträglicher Selbsttäuschung beruhen.

---

## 1. Ziel

Das System speichert jede neue Trade-Entscheidung zeitlich und fachlich nachvollziehbar.

Gespeichert wird:

- was gekauft, getrimmt, pausiert, fortgeführt, verkauft oder zurück auf die Watchlist gesetzt wurde
- wann es passierte
- zu welchem Preis
- mit welcher Menge
- ob live, paper oder pseudo
- mit welchem Snapshot-Kontext
- mit welcher Score-/Policy-/Model-Version
- warum ein Exit/Trim/Return passierte
- welches Ergebnis daraus entstand

Damit wird v0.5 möglich:

```text
Evidence Engine: Was hat in ähnlichen Situationen historisch funktioniert?
```

Ohne v0.4 wäre jede spätere Evidence-Auswertung Scheingenauigkeit.

---

## 2. Nicht-Ziele von v0.4

v0.4 liefert ausdrücklich nicht:

- keine Evidence Engine
- kein ML
- kein LLM
- keine automatische Kauf-/Verkaufsentscheidung
- kein Redis
- keine Message Queue für Trade-Writes
- kein Macro-Layer
- keine Broker-Integration
- keine automatische Bestandsrekonstruktion alter Trades

---

## 3. Zentrale Architekturentscheidung

Der Truth Layer besteht aus:

```text
trade_campaign
trade_event
trade_migration_log
```

### `trade_campaign`

Eine Campaign ist ein vollständiger Trade-Zyklus pro Instrument.

Beispiel:

```text
AAPL gekauft → nachgekauft → 30 % getrimmt → Rest verkauft
```

Das ist eine Campaign mit mehreren Events.

### `trade_event`

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

### `trade_migration_log`

Dokumentiert, wie bestehende Altpositionen in den Truth Layer überführt wurden.

---

## 4. Schema-Migration vs. Bestandsmigration

Unterscheidung:

```text
Schema-Migration:
  legt leere Tabellen an

Bestandsmigration:
  überführt alte Portfolio-Positionen in trade_campaign/trade_event
```

Finaler Stand:

```text
Schema Foundation abgeschlossen.
Legacy-Positionen wurden später kontrolliert als migration_seed migriert.
```

---

## 5. Datenmodell

### 5.1 `trade_campaign`

Konzeptionelle Pflichtfelder:

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

Wichtig:

```text
entry_macro_snapshot_id und exit_macro_snapshot_id bleiben in v0.4 nullable ohne FK.
macro_snapshot existiert erst später.
```

### 5.2 `trade_event`

Konzeptionelle Pflichtfelder:

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

Event Types:

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

Wichtig:

```text
event_price und quantity bleiben nullable.
pause, resume und migration_seed sind keine echten Ausführungen.
```

### 5.3 `trade_migration_log`

Konzeptionell:

```text
id
instrument_id
trade_campaign_id
migration_status: full | partial | manual_seed
migration_notes
migrated_at
```

---

## 6. Snapshot-Timing-Policy

Grundregel:

```text
Der verknüpfte Snapshot ist der letzte abgeschlossene Snapshot vor dem Event.
```

Snapshot-Datumsfelder:

```text
as_of_date
```

Nicht:

```text
snapshot_date
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

Zweck:

```text
Look-ahead-Bias verhindern.
```

Legacy Seed Sonderregel:

```text
Wenn opened_at vor Snapshot-Datum liegt, wird keine Snapshot-ID gesetzt.
Lieber NULL als falsche historische Wahrheit.
```

---

## 7. Versionierung

Versionsstrings gehören auf jedes `trade_event`, nicht nur auf `trade_campaign`.

Grund:

```text
Entry und Exit können mit unterschiedlichen Score-/Policy-Versionen passieren.
```

Zentrale Config:

```text
config/system_versions.json
```

Empfohlener Inhalt:

```json
{
  "scoring_version": "ksm.1.0",
  "policy_version": "sepa.1.0",
  "model_version": null,
  "macro_version": null
}
```

Warum JSON:

```text
PHP und Python können JSON ohne zusätzliche Abhängigkeiten lesen.
```

---

## 8. TradeEventWriter als Wahrheitsschreibstelle

Trade-Writes müssen synchron und atomar sein.

Richtige Logik:

```text
BEGIN
  trade_campaign schreiben/aktualisieren
  trade_event schreiben
  instrument state aktualisieren
COMMIT
```

Falsche Logik:

```text
Instrument ist Portfolio
Trade-Event kommt später vielleicht aus der Queue
```

Kernsatz:

```text
TradeEventWriter ist die einzige normale Schreibstelle für Portfolio-Trade-Zustände.
```

Wenn irgendwo direkt `instrument.is_portfolio` geändert wird, ohne ein Trade-Event zu erzeugen, ist v0.4 fachlich gebrochen.

---

## 9. Keine neuen Runtime-Libraries für v0.4

Für v0.4 reicht der bestehende Stack:

```text
Symfony
Doctrine
Doctrine Migrations
Twig
PHPUnit
MariaDB
bestehender web/job/migrate Stack
```

Beschluss:

```text
Keine neue PHP-Library.
Keine neue Python-Library.
Kein neuer Pflichtcontainer für Runtime.
```

Agent-Runner Light ist Entwickler-/Agenten-Tooling, nicht v0.4-Produktarchitektur.

---

## 10. Symfony statt Python-Subprozess für UI-Trade-Writes

Trade-Writes aus UI-Flows laufen über Symfony-Service, nicht über Python-Prozessaufruf.

Grund:

```text
Der aktuelle Portfolio/Watchlist-State sitzt in Symfony.
Die UI-Aktion muss sofort transaktional Campaign + Event + Instrument-State schreiben.
```

Python bleibt sinnvoll für:

```text
Legacy-Migration
P&L-Recalculate
Batch-/Maintenance-Jobs
```

---

## 11. Technical Work Packages – finaler Stand

### Chunk 1 — Database Schema / Persistence Model

Status: **abgeschlossen**

Baut:

```text
trade_campaign
trade_event
trade_migration_log
```

---

### Chunk 2 — Configuration Management

Status: **abgeschlossen**

Baut:

```text
config/system_versions.json
TradeVersionProvider
```

---

### Chunk 3 — Snapshot Resolution / Temporal Data Access

Status: **abgeschlossen**

Baut:

```text
TradeSnapshotResolver
```

Regel:

```text
as_of_date < DATE(event_timestamp)
```

---

### Chunk 4 — Finite State Machine + Domain Validation

Status: **abgeschlossen**

Baut:

```text
TradeStateMachine
TradeEventValidator
TradeValidationException
```

Regeln:

```text
entry nur ohne offene Campaign
trim bei open/trimmed
pause bei open/trimmed
resume bei paused
hard_exit bei open/trimmed/paused
return_to_watchlist bei open/trimmed/paused
keine Events nach Terminal-State
```

Exit-Reason ist Pflicht bei:

```text
trim
hard_exit
return_to_watchlist
```

---

### Chunk 5 — Domain Calculation Service / P&L Engine

Status: **abgeschlossen**

Baut:

```text
TradePnlCalculator
```

Wichtige Konvention:

```text
realized_pnl_pct ist Ratio.
0.30 = 30 %
```

---

### Chunk 6 — Application Service / Transaction Script

Status: **abgeschlossen**

Baut:

```text
TradeEventWriter
TradeEventWriteResult
```

Macht atomar:

```text
Campaign erstellen/laden
State prüfen
Snapshots auflösen
Versionen setzen
Event schreiben
Campaign aktualisieren
Instrument-State aktualisieren
P&L setzen
```

Finale Full-Exit-Regel:

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

### Chunk 7 — Entry UI

Status: **abgeschlossen**

Ziel:

```text
Watchlist → Portfolio nur über dokumentierten Entry.
```

Baut:

```text
Entry Modal/Form
POST /instrument/{id}/portfolio/entry
```

---

### Chunk 8 — Exit / Trim / Pause / Return UI

Status: **abgeschlossen**

Events:

```text
trim
hard_exit
return_to_watchlist
pause
resume
```

DoD:

```text
Trim schließt nicht vollständig.
Hard Exit schließt Campaign.
Return schließt Campaign und setzt Instrument zurück auf Watchlist.
Pause schließt nicht.
Exit-Reason Dropdown Pflicht bei Exit/Trim/Return.
```

---

### Chunk 9a — Minimal Audit View

Status: **abgeschlossen**

Ziel:

```text
Trade-Wahrheit sichtbar machen.
```

Anzeigen:

```text
Campaigns
Events
Snapshot-IDs
Versionstrings
P&L
States
```

---

### Chunk 10a–10d — Legacy Integrity / Seed Plan / Template / Validator

Status: **abgeschlossen**

Baut:

```text
Legacy Integrity Report
Legacy Seed Plan
Legacy Seed Template
Legacy Seed Validator
```

---

### Legacy SQL Seed

Status: **abgeschlossen**

Ergebnis:

```text
21 bestehende Portfolio-Positionen wurden als migration_seed migriert.
21 trade_campaign
21 trade_event
21 trade_migration_log
```

Snapshot-IDs wurden bewusst `NULL` gelassen, um Hindsight Bias zu vermeiden.

Wichtiger Satz:

```text
Wir migrieren nicht historische Wahrheit.
Wir migrieren heutige Position mit bestmöglich auditierbarem Ursprung.
```

---

## 13. Finaler Test-/Audit-Abschluss

Abgeschlossene Nacharbeiten:

```text
T1 Decimal/Money Safety Audit
T2 Campaign-Level realized_pnl_pct Verifikation
T2b Test-Payload-Fix für exit_reason
T2c Portfolio Flag After Full Exit
```

Lokaler finaler Teststand nach T2c:

```text
TradeEventWriterIntegrationTest
OK (8 tests, 32 assertions)
```

Damit sind die kritischen Truth-Layer-Bugs geschlossen:

```text
realized_pnl_pct ist Campaign-Level, nicht letzter Exit-Anteil.
hard_exit entfernt Instrument aus Portfolio.
return_to_watchlist entfernt Instrument aus Portfolio.
trim lässt Instrument im Portfolio.
```

---

## 14. Bekannte technische Schuld

### Float-Arithmetik

DB-Spalten nutzen `DECIMAL`, aber Services verwenden intern teilweise PHP `float`.

Bewertung:

```text
Für v0.4 akzeptabel.
Für v0.5 kein Blocker.
Für spätere exakte Money-/Broker-/Tax-Logik als T3 Decimal Strategy prüfen.
```

Mögliche spätere Optionen:

```text
DecimalCalculator mit string-in/string-out
BCMath
brick/math
MoneyPHP nur falls Währungsbeträge statt quantity × price im Zentrum stehen
```

### Concurrency

`SELECT FOR UPDATE` / Unique Constraints für offene Campaigns bleiben spätere technische Schuld.

---

## 15. Warum v0.4 gebraucht wird

Vor v0.4 konnte ein Instrument im System einfach den Zustand wechseln:

```text
Watchlist → Portfolio
```

Das erzeugt keine echte Entscheidungs-Historie.

v0.4 macht daraus:

```text
Watchlist → Entry Event → Campaign → Portfolio
```

Und später:

```text
Portfolio → Trim/Exit/Return Event → P&L → History
```

Erst dadurch kann das System später beantworten:

```text
Welche Entries haben funktioniert?
Welche Exits haben Rendite zerstört?
Waren Stop-Losses hilfreich?
Verkaufe ich Gewinner zu früh?
Sind Paper-Trades besser als Live-Trades?
Funktionieren SEPA-grüne Entries wirklich?
Welche Score-Version war aktiv?
Welche Snapshot-Daten waren zum Entscheidungszeitpunkt verfügbar?
```

Ohne diese Schicht ist jede spätere Evidence Engine nur ein Narrativ auf unsauberen Daten.

---

## 16. Abschlussnotiz

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
