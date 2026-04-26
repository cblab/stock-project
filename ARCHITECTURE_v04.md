# ARCHITECTURE v0.4 – Truth Layer

stock-project · v0.4 · aktualisierter Arbeitsstand

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

---

## 1. Ziel

Das System soll ab v0.4 jede neue Trade-Entscheidung zeitlich und fachlich nachvollziehbar speichern.

Gespeichert werden muss:

- was gekauft oder verkauft wurde
- wann es passierte
- zu welchem Preis
- mit welcher Menge
- ob live, paper oder pseudo
- mit welchem Snapshot-Kontext
- mit welcher Score-/Policy-/Model-Version
- warum ein Exit/Trim/Return passierte
- welches Ergebnis daraus entstand

Damit wird später v0.5 möglich:

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

### trade_campaign

Eine Campaign ist ein vollständiger Trade-Zyklus pro Instrument.

Beispiel:

```text
POET gekauft → nachgekauft → 30 % getrimmt → Rest verkauft
```

Das ist eine Campaign mit mehreren Events.

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

Dokumentiert später, wie sauber bestehende Altpositionen in den Truth Layer überführt wurden.

---

## 4. Wichtige Korrektur: Schema-Migration vs. Bestandsmigration

Chunk 1 braucht eine Doctrine-Schema-Migration, weil die Tabellen existieren müssen.

Chunk 1 braucht aber keine Migration bestehender Portfolio-Positionen.

Unterscheidung:

```text
Schema-Migration:
  legt leere Tabellen an

Bestandsmigration:
  überführt alte Portfolio-Positionen in trade_campaign/trade_event
```

Beschluss:

```text
Bestandsmigration kommt nicht in Chunk 1.
Bestehende Positionen können zunächst legacy bleiben.
Neue Positionen laufen ab v0.4 sauber über den Truth Layer.
```

---

## 5. Datenmodell – Zielbild

### 5.1 trade_campaign

Pflichtfelder konzeptionell:

```text
id
instrument_id
trade_type: live | paper | pseudo
state
entry_thesis
invalidation_rule
outcome_tag
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

### 5.2 trade_event

Pflichtfelder konzeptionell:

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

### 5.3 trade_migration_log

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

Im bestehenden Repo heißen Snapshot-Datumsfelder:

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

---

## 7. Versionierung

Versionsstrings gehören auf jedes trade_event, nicht nur auf trade_campaign.

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

## 8. Keine Redis-Queue für v0.4

Beschluss:

```text
Redis wird für v0.4 nicht eingeführt.
```

Grund:

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

Asynchronität ist später sinnvoll für:

```text
snapshot refresh
evidence aggregation
daily briefing
macro refresh
LLM explanations
```

Aber nicht für Wahrheitserzeugung.

---

## 9. Keine neuen Libraries, keine Docker-Änderung für v0.4

Für die eigentliche v0.4-Implementierung reicht der bestehende Stack:

```text
Symfony
Doctrine
Doctrine Migrations
Twig
PHPUnit
PyMySQL
PyYAML vorhanden, aber nicht nötig
MariaDB
bestehender web/job/migrate Stack
```

Beschluss:

```text
Keine neue PHP-Library.
Keine neue Python-Library.
Kein Redis.
Kein neuer Pflichtcontainer für Runtime.
```

Agent-Runner Light ist nur Entwickler-/Agenten-Tooling, nicht v0.4-Produktarchitektur.

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

## 11. Die 10 Technical Work Packages

### Chunk 1 — Database Schema / Persistence Model

Ziel:

```text
Leere Tabellen für den Truth Layer schaffen.
```

Baut:

```text
trade_campaign
trade_event
trade_migration_log
```

Nicht-Ziele:

```text
keine Bestandsmigration
keine UI
keine P&L-Logik
keine Services
keine Daten anfassen
```

DoD:

```text
Tabellen existieren.
FKs passen zu bestehenden Typen.
Altbestand bleibt unverändert.
```

---

### Chunk 2 — Configuration Management

Ziel:

```text
Zentrale Versionsquelle für Trade-Events schaffen.
```

Baut:

```text
config/system_versions.json
TradeVersionProvider
```

DoD:

```text
scoring_version und policy_version kommen aus Config.
model_version und macro_version dürfen NULL sein.
Keine Versionen im Controller hardcoden.
```

---

### Chunk 3 — Snapshot Resolution / Temporal Data Access

Ziel:

```text
Letzten gültigen Snapshot vor Event-Zeitpunkt finden.
```

Baut:

```text
TradeSnapshotResolver
```

Regel:

```text
as_of_date < DATE(event_timestamp)
```

DoD:

```text
Snapshot gestern wird gefunden.
Snapshot gleicher Tag wird nicht gefunden.
Kein Snapshot ergibt NULL.
```

---

### Chunk 4 — Finite State Machine + Domain Validation

Ziel:

```text
Erlaubte Trade-Übergänge und Pflichtfelder zentral erzwingen.
```

Baut:

```text
TradeStateMachine
TradeEventValidator
TradeValidationException
```

Regeln:

```text
entry nur ohne offene Campaign
trim nur bei open/trimmed
pause nur bei open/trimmed
resume nur bei paused
hard_exit bei open/trimmed/paused
return_to_watchlist bei open/trimmed/paused
keine Events nach Terminal-State
```

DoD:

```text
Exit-Reason Pflicht bei trim/hard_exit/return.
Keine Exit-Reason bei entry/add/pause/resume/migration_seed.
Nicht mehr verkaufen als Bestand.
```

---

### Chunk 5 — Domain Calculation Service / P&L Engine

Ziel:

```text
Brutto-P&L und P&L-Prozent deterministisch berechnen.
```

Baut:

```text
TradePnlCalculator
```

DoD:

```text
Partial Trim korrekt.
Full Exit korrekt.
Fees berücksichtigt.
Steuer optional, nicht erzwungen.
```

---

### Chunk 6 — Application Service / Transaction Script

Ziel:

```text
Zentrale Wahrheitsschreibstelle bauen.
```

Baut:

```text
TradeEventWriter
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

DoD:

```text
Entry erzeugt Campaign + Event.
Trim erzeugt Event + State trimmed.
Pause erzeugt Event + State paused.
Exit erzeugt Event + Closed-State.
Rollback bei Fehler.
```

---

### Chunk 7 — Command/UI Flow for Entry

Ziel:

```text
Watchlist → Portfolio nur noch über dokumentierten Entry.
```

Baut:

```text
TradeEntryController
Entry Modal/Form
POST /instrument/{id}/portfolio/entry
```

Felder:

```text
Kaufpreis
Menge
Gebühren
Kaufdatum
Entry Thesis
Invalidation Rule
Trade Type live/paper/pseudo
```

DoD:

```text
Alter Toggle-Portfolio-Button ist aus normaler UI entfernt.
Entry läuft über TradeEventWriter.
Instrument wird erst nach Trade-Event Portfolio.
```

---

### Chunk 8 — Command/UI Flow for Exit Events

Ziel:

```text
Portfolio-Aktionen als Trade-Events erfassen.
```

Baut:

```text
TradeCampaignEventController
Exit/Trim/Pause/Return Modal
POST /trade-campaign/{id}/event
```

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

### Chunk 9 — Read Model / Query Model / Reporting UI

Ziel:

```text
Trade-Wahrheit sichtbar machen.
```

Baut:

```text
/trade-history
/trade-campaign/{id}
TradeHistoryQuery
```

Anzeigen:

```text
Ticker
Entry-Datum
Entry-Preis
Exit-Datum
Exit-Preis
Exit-Reason
Haltedauer
P&L %
State
Trade Type
Outcome Tag
Events chronologisch
Snapshot-Links oder NULL
Versionsstrings
```

DoD:

```text
Live/Paper/Pseudo werden nicht vermischt.
Events sind chronologisch sichtbar.
Snapshot- und Versionskontext sichtbar.
```

---

### Chunk 10 — Legacy Data Migration + Reconciliation Job

Ziel:

```text
Altbestand optional in Truth Layer überführen.
```

Baut:

```text
run_trade_migration.py
run_trade_pnl_recalculate.py
```

Wichtig:

```text
Dieser Chunk ist nicht Voraussetzung für neue Positionen.
```

Migration:

```text
bestehende instrument.is_portfolio = 1
→ trade_campaign state=open
→ trade_event event_type=migration_seed
→ trade_migration_log
```

DoD:

```text
Dry-run vorhanden.
Idempotent.
Keine historischen Daten geraten.
manual_seed dokumentiert Lücken.
```

---

## 12. Reihenfolge

```text
1 Schema Foundation
2 Version Config
3 Snapshot Resolver
4 State Machine + Validator
5 P&L Calculator
6 TradeEventWriter
7 Entry Flow UI
8 Exit / Trim / Pause / Return Flow
9 Trade History + Campaign Detail
10 Legacy Migration + Recalculator + Final Validation
```

Kritischer Pfad für neue Trades:

```text
1 → 2 → 3 → 4 → 5 → 6 → 7 → 8 → 9
```

Chunk 10 ist Altlasten-Nachzug.

---

## 13. v0.4 Minimal vs. v0.4 Full

### v0.4 Minimal

Fertig, wenn:

```text
neue Entries erzeugen Campaign + Event
neue Exits erzeugen Event
Snapshot-Regel wird eingehalten
State Machine greift
P&L wird für neue Trades berechnet
Trade-History zeigt neue Campaigns
```

Nicht zwingend:

```text
alte Portfolio-Positionen migriert
```

### v0.4 Full

Zusätzlich:

```text
bestehende Portfolio-Positionen als manual_seed erfasst
trade_migration_log dokumentiert Lücken
P&L-Recalculator vorhanden
5 manuelle Stichproben validiert
```

---

## 14. Agent-Runner Light

Agent-Runner Light ist kein Teil der Produktarchitektur, sondern Arbeitsinfrastruktur.

Ziel:

```text
Susi kann Syntax prüfen, Doctrine-Kommandos dry-runnen, Tests ausführen und Diffs kontrollieren.
```

Nicht geben:

```text
Docker-Socket
DB-root
Host-root
Volume-Löschrechte
Deployment-Rechte
```

Erlaubt:

```text
git status
git diff
php bin/console doctrine:migrations:status
php bin/console doctrine:migrations:migrate --dry-run
php bin/console lint:container
php bin/phpunit
php -l <datei>
composer validate
```

Nicht erlaubt:

```text
docker compose down -v
docker system prune
rm -rf
doctrine:schema:update --force
DROP / TRUNCATE
git push --force
```

---

## 15. Warum das gebraucht wird

Aktuell kann ein Instrument im System einfach den Zustand wechseln:

```text
Watchlist → Portfolio
```

Das erzeugt aber keine echte Entscheidungs-Historie.

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

## 16. Kernsatz für die Umsetzung

```text
TradeEventWriter ist die einzige normale Schreibstelle für Portfolio-Trade-Zustände.
```

Wenn irgendwo weiterhin direkt `instrument.is_portfolio` geändert wird, ohne ein Trade-Event zu erzeugen, ist v0.4 gebrochen.

---

## 17. Kurzfassung

```text
v0.4 baut nicht Intelligenz.
v0.4 baut Wahrheit.
```

Die 10 Chunks bauen nacheinander:

```text
1. Persistenz
2. Versionierung
3. zeitlich saubere Snapshot-Verknüpfung
4. erlaubte Zustände
5. Ergebnisrechnung
6. atomare Schreiblogik
7. Entry-UI
8. Exit-UI
9. History-UI
10. Legacy-Nachzug
```

Das ist das Fundament für v0.5 Evidence Engine.
