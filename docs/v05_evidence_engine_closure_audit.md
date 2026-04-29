# v0.5 Evidence Engine Closure Audit

Stand: 2026-04-29

## A. Executive Closure Decision

- v0.5 closed: yes

Begruendung:

- `eligible_full` ist auf `main` nicht mehr nur modelliert, sondern ueber `SnapshotValidationService` technisch freigeschaltet.
- `EvidenceEligibilityEvaluator` laesst `eligible_full` nur zu, wenn die DB-level Snapshot-/Run-Validierung erfolgreich ist.
- Ungueltige, fehlende oder seed-basierte Samples werden weiterhin konservativ auf `eligible_outcome_only` oder `excluded` zurueckgestuft.
- Die Writer-Provenance fuer SEPA, EPA und BuySignal ist vorhanden, und C10d deckt Immutability/Repairability sowohl als SQL-shape-Tests als auch per MariaDB-Integration ab.
- Aggregation, Confidence und Readout bleiben neutral: keine Recommendation-Semantik, keine Buy/Sell-Aussagen, keine Forward-Return-Leakage in die Evidence-Klassifikation.

Blocker:

- keine fachlichen oder technischen Blocker fuer den v0.5-Abschluss im aktuellen `main`

Antwort auf die Kernfrage:

- Nach aktuellem `main` kann das System Erfolg nicht mehr als `eligible_full` mit Snapshot-Daten erklaeren, die zum Entry-Zeitpunkt nicht nachweislich verfuegbar waren.
- Nicht valide Snapshot-Kontexte bleiben zwar als `eligible_outcome_only` im Outcome-Layer nutzbar, werden aber gerade nicht als voll validierte Entry-Evidence behandelt.

Tooling-Hinweis:

- GitNexus war verfuegbar, der Index war jedoch stale. Der Audit basiert deshalb primaer auf aktuellem Code und aktuellen Tests, nicht auf dem Index-Stand.

## B. Evidence Classes

### `eligible_outcome_only`

Bedeutung:

- Trade-Outcome ist verwendbar.
- Snapshot-Kontext ist fehlend, seed-basiert oder anti-hindsight-seitig nicht voll validiert.
- Das Sample darf in Outcome-Aggregationen einfliessen, aber nicht als voll validierte Entry-Evidence gelten.

### `eligible_full`

Bedeutung:

- Trade-Outcome ist verwendbar.
- Zugehoerige Entry-Snapshots sind DB-level gegen Anti-Hindsight- und Run-Provenance-Regeln validiert.
- Nur diese Klasse darf als fachlich vollwertige Evidence fuer spaetere Signal-Layer verwendet werden.

### `excluded`

Bedeutung:

- Sample ist fuer Aggregation unzulaessig.
- Beispiele: non-terminaler Campaign-State, `closed_at` fehlt, `realized_pnl_pct` fehlt, invalide Zeitreihenfolge, unbekannter State.

## C. Anti-Hindsight Invariants

Die aktuelle technische Pruefung sitzt in [web/src/Service/Evidence/SnapshotValidationService.php](E:/stock-project/web/src/Service/Evidence/SnapshotValidationService.php).

Explizit geprueft:

1. Snapshot existiert:
   - `snapshot_id` darf nicht `NULL` sein.
   - Snapshot-Zeile muss im jeweiligen Snapshot-Table existieren.
2. `instrument_id` matcht:
   - `snapshot.instrument_id` muss dem erwarteten `instrument_id` des Samples entsprechen.
3. `source_run_id` vorhanden:
   - `snapshot.source_run_id` darf nicht `NULL` sein.
4. `available_at` vorhanden:
   - `snapshot.available_at` darf nicht `NULL` sein.
5. `available_at <= entry timestamp`:
   - Snapshot muss spaetestens zum Entry-Zeitpunkt verfuegbar gewesen sein.
6. `pipeline_run` existiert:
   - `pipeline_run.id = snapshot.source_run_id` muss ueber den Join aufloesbar sein.
7. `pipeline_run.status = success`:
   - nur erfolgreiche Runs sind als voll validierte Provenance zulaessig.
8. `pipeline_run.exit_code = 0`:
   - nicht nur Status, sondern auch Exit-Code muss sauber sein.
9. `pipeline_run.finished_at` vorhanden:
   - abgeschlossene erfolgreiche Runs brauchen einen Abschlusszeitpunkt.
10. `available_at >= pipeline_run.finished_at`:
   - Anti-Hindsight-Gelaender: Snapshot kann nicht verfuegbar sein, bevor der produzierende Run beendet wurde.

Bewertung:

- Diese Invarianten decken die relevante Anti-Hindsight-Kette fachlich vollstaendig ab.
- Ein Snapshot mit falscher oder zu spaeter Verfuegbarkeit kann damit nicht `eligible_full` werden.

## D. Writer Provenance

Gepruefte Dateien:

- [stock-system/src/sepa/persistence.py](E:/stock-project/stock-system/src/sepa/persistence.py)
- [stock-system/src/epa/persistence.py](E:/stock-project/stock-system/src/epa/persistence.py)
- [stock-system/src/db/buy_signal_snapshot.py](E:/stock-project/stock-system/src/db/buy_signal_snapshot.py)
- passende Tests unter [stock-system/tests](E:/stock-project/stock-system/tests)

### SEPA Writer

- schreibt `source_run_id` beim Write explizit mit
- schreibt `available_at` nullable
- finalisiert ueber `finalize_snapshots_for_run(source_run_id, finished_at)`
- `finalize_snapshots_for_run()` setzt `available_at` nur fuer Zeilen mit passender `source_run_id` und `available_at IS NULL`

Bewertung:

- SEPA finalisiert `available_at` erst ueber den separaten Success-/Finished-Pfad.

### EPA Writer

- schreibt `source_run_id` beim Write explizit mit
- schreibt `available_at` nullable
- finalisiert ueber `finalize_snapshots_for_run(source_run_id, finished_at)`
- `finalize_snapshots_for_run()` setzt `available_at` nur fuer Zeilen mit passender `source_run_id` und `available_at IS NULL`

Bewertung:

- EPA finalisiert `available_at` ebenfalls erst ueber den separaten Success-/Finished-Pfad.

### BuySignal Writer

- schreibt `source_run_id` und `available_at` direkt in den Snapshot
- `write_from_pipeline_item()` reicht `source_run_id` und `available_at` transparent an `write()` durch

Bewertung:

- Der BuySignal-Writer selbst erzwingt `success` nicht aktiv im Write-Call.
- Fuer Evidence-Sicherheit ist das aber ausreichend, weil `eligible_full` spaeter nur ueber den Validator freigeschaltet wird und dieser `pipeline_run.status = success`, `exit_code = 0` und `finished_at` erzwingt.
- Fachlich zaehlt daher nicht allein der Write, sondern die Kombination aus Provenance-Feldern plus nachgelagerter DB-level Validation.

### Finalisierte Rows sind immutable

Belegt durch:

- SQL-shape-Tests:
  - [stock-system/tests/test_sepa_snapshot_writer.py](E:/stock-project/stock-system/tests/test_sepa_snapshot_writer.py)
  - [stock-system/tests/test_epa_snapshot_writer.py](E:/stock-project/stock-system/tests/test_epa_snapshot_writer.py)
  - [stock-system/tests/test_buy_signal_snapshot_writer.py](E:/stock-project/stock-system/tests/test_buy_signal_snapshot_writer.py)
- MariaDB-Integration:
  - [stock-system/tests/test_sepa_snapshot_integration.py](E:/stock-project/stock-system/tests/test_sepa_snapshot_integration.py)
  - [stock-system/tests/test_epa_snapshot_integration.py](E:/stock-project/stock-system/tests/test_epa_snapshot_integration.py)
  - [stock-system/tests/test_buy_signal_snapshot_integration.py](E:/stock-project/stock-system/tests/test_buy_signal_snapshot_integration.py)

Gepruefte Eigenschaften:

- Business-Felder bleiben nach Finalisierung stabil.
- `source_run_id` bleibt nach Finalisierung stabil.
- `available_at` bleibt nach Finalisierung stabil.
- `updated_at` bleibt nach Finalisierung stabil.

### Unfinalisierte Rows bleiben reparierbar

Gepruefte Eigenschaften:

- Business-Felder koennen vor Finalisierung noch aktualisiert werden.
- `source_run_id` kann vor Finalisierung auf einen neuen non-null Run wechseln.
- `NULL source_run_id` loescht eine bestehende `source_run_id` nicht.

## E. Eligibility Integration

Gepruefte Dateien:

- [web/src/Service/Evidence/EvidenceEligibilityEvaluator.php](E:/stock-project/web/src/Service/Evidence/EvidenceEligibilityEvaluator.php)
- [web/src/Service/Evidence/SnapshotValidationService.php](E:/stock-project/web/src/Service/Evidence/SnapshotValidationService.php)
- [web/tests/Service/Evidence/EvidenceEligibilityEvaluatorTest.php](E:/stock-project/web/tests/Service/Evidence/EvidenceEligibilityEvaluatorTest.php)

Ergebnis:

- `EvidenceEligibilityEvaluator` nutzt `SnapshotValidationService`, wenn dieser verdrahtet ist.
- Validierte Snapshots koennen `eligible_full` erzeugen.
- Invalidierte oder fehlende Snapshots downgraden auf `eligible_outcome_only`.
- `migration`- und `manual`-Seed bleiben auch bei validen Snapshots `eligible_outcome_only`.
- `excluded`-Regeln bleiben vorrangig vor Snapshot-Freigaben.

Wichtige Reihenfolge:

1. State-/Terminal-Pruefung
2. Zeitreihenfolge
3. Pflichtfelder (`closed_at`, `realized_pnl_pct`)
4. Seed-Downgrade
5. Missing-Snapshot-Downgrade
6. Invalid-Snapshot-Downgrade
7. nur dann `eligible_full`

Bewertung:

- Die Integration ist fachlich konsistent.
- Erfolg kann damit nicht "durchrutschen" und gleichzeitig als voll validierte Snapshot-Evidence erscheinen.

## F. Aggregation / Confidence / Readout

Gepruefte Dateien:

- [web/src/Service/Evidence/EntryEvidenceAggregator.php](E:/stock-project/web/src/Service/Evidence/EntryEvidenceAggregator.php)
- [web/src/Service/Evidence/ExitEvidenceAggregator.php](E:/stock-project/web/src/Service/Evidence/ExitEvidenceAggregator.php)
- [web/src/Service/Evidence/EvidenceConfidenceCalculator.php](E:/stock-project/web/src/Service/Evidence/EvidenceConfidenceCalculator.php)
- [web/src/Service/Evidence/EvidenceReadoutBuilder.php](E:/stock-project/web/src/Service/Evidence/EvidenceReadoutBuilder.php)
- zugehoerige Tests unter [web/tests/Service/Evidence](E:/stock-project/web/tests/Service/Evidence)

Ergebnis:

- `EntryEvidenceAggregator` arbeitet auf Eligibility-Klassen.
- `ExitEvidenceAggregator` arbeitet auf Eligibility-Klassen.
- Beide Aggregatoren kombinieren `eligible_full` und `eligible_outcome_only` fuer Outcome-Metriken, zaehlen die Zusammensetzung aber getrennt.
- `EvidenceConfidenceCalculator` ist integriert und basiert auf `n` mit optionalem SEM-Cap.
- `EvidenceReadoutBuilder` erzeugt Warnings/Codes wie:
  - `contains_outcome_only_samples`
  - `no_full_entry_evidence`
  - `contains_excluded_samples`
  - `low_confidence_evidence`
- `EvidenceReadoutBuilder` fuegt keine Recommendation-Semantik hinzu.

Bewertung:

- Der Readout bleibt neutral und maschinenlesbar.
- Es gibt keine implizite Kauf-/Verkaufsempfehlung und keine Vorwaertsretouren-Leakage in die Decision-Semantik.

## G. Tests

Relevante Testgruppen:

- [web/tests/Service/Evidence/EvidenceEligibilityEvaluatorTest.php](E:/stock-project/web/tests/Service/Evidence/EvidenceEligibilityEvaluatorTest.php)
- [web/tests/Service/Evidence/SnapshotValidationServiceTest.php](E:/stock-project/web/tests/Service/Evidence/SnapshotValidationServiceTest.php)
- [web/tests/Service/Evidence/EvidenceReadoutBuilderTest.php](E:/stock-project/web/tests/Service/Evidence/EvidenceReadoutBuilderTest.php)
- [web/tests/Service/Evidence/EntryEvidenceAggregatorTest.php](E:/stock-project/web/tests/Service/Evidence/EntryEvidenceAggregatorTest.php)
- [web/tests/Service/Evidence/ExitEvidenceAggregatorTest.php](E:/stock-project/web/tests/Service/Evidence/ExitEvidenceAggregatorTest.php)
- [web/tests/Service/Evidence/TradeOutcomeExtractorIntegrationTest.php](E:/stock-project/web/tests/Service/Evidence/TradeOutcomeExtractorIntegrationTest.php)
- [stock-system/tests/test_sepa_snapshot_writer.py](E:/stock-project/stock-system/tests/test_sepa_snapshot_writer.py)
- [stock-system/tests/test_epa_snapshot_writer.py](E:/stock-project/stock-system/tests/test_epa_snapshot_writer.py)
- [stock-system/tests/test_buy_signal_snapshot_writer.py](E:/stock-project/stock-system/tests/test_buy_signal_snapshot_writer.py)
- [stock-system/tests/test_sepa_snapshot_integration.py](E:/stock-project/stock-system/tests/test_sepa_snapshot_integration.py)
- [stock-system/tests/test_epa_snapshot_integration.py](E:/stock-project/stock-system/tests/test_epa_snapshot_integration.py)
- [stock-system/tests/test_buy_signal_snapshot_integration.py](E:/stock-project/stock-system/tests/test_buy_signal_snapshot_integration.py)

Einordnung von C10a/b/c/d im aktuellen Stand:

- C10a-C10c sind im aktuellen `main` als lauffaehige Kette sichtbar:
  - Snapshot-Validation-Service aktiv
  - Eligibility-Integration aktiv
  - Writer-Provenance-Felder aktiv
- C10d ist durch die Writer-Immutability- und MariaDB-Integrationstests explizit abgesichert.

## H. Known Non-Blockers / Follow-ups

### Non-Blocker

1. `EvidenceTradeSample` nutzt `openedAt` als Entry-Zeitpunkt.
   - `TradeOutcomeExtractor` verwendet aktuell `trade_campaign.opened_at`.
   - Der kanonische Write-Pfad in [web/src/Service/Trade/TradeEventWriter.php](E:/stock-project/web/src/Service/Trade/TradeEventWriter.php) setzt `opened_at` fuer `entry`/`migration_seed` direkt aus `event_timestamp`.
   - Damit ist im aktuellen Truth-Layer-Write-Pfad kein akuter Hindsight-Spalt sichtbar.
   - Als Follow-up bleibt dennoch sinnvoll, spaeter optional direkt gegen den Entry-Event-Timestamp zu validieren oder die Invariante explizit zu dokumentieren.

2. BuySignal Snapshot bleibt DBAL-/Table-only ohne Doctrine Entity.
   - Das ist im aktuellen Stand kein Blocker, weil `SnapshotValidationService` den BuySignal-Snapshot bewusst per DBAL gegen `instrument_buy_signal_snapshot` validiert.

3. Kein Backfill alter Snapshots.
   - Alte Rows ohne Provenance bleiben korrekt `eligible_outcome_only`.
   - Das ist konservativ und kein Closure-Blocker.

4. Keine Snapshot-Versionierung fuer Policy/Scoring.
   - `scoring_version`, `policy_version`, `model_version`, `macro_version` werden in Samples gefuehrt, aber nicht als harter Eligibility-Gate verwendet.
   - Das ist ein v0.6/v0.7-Thema, kein v0.5-Blocker.

5. Keine Exposure Layer / API / UI.
   - Gehoert nicht zu v0.5 und blockiert den fachlichen Abschluss nicht.

### Keine Blocker

- Aus dem aktuellen `main` ergibt sich kein offener Punkt, der `eligible_full` fachlich unsicher machen wuerde.
- Insbesondere werden ungueltige Snapshot-Kontexte nicht auf `eligible_full` hochgestuft.

## I. v0.6 Gate

Da `v0.5 closed = yes` gilt:

- v0.6 darf starten

v0.6 Scope:

- Signal Evidence Layer auf SEPA-/EPA-Buckets
- weiterhin keine Recommendation Engine
- keine Buy-/Sell-UI
- keine Forward-Return-Leakage

Startbedingungen fuer v0.6:

1. `eligible_full` bleibt der einzige zulaessige Eingang fuer spaetere signalbasierte Entry-Evidence.
2. `eligible_outcome_only` darf nicht stillschweigend als Signal-Evidence behandelt werden.
3. Neue Buckets muessen die bestehenden Warning-/Guardrail-Semantiken erhalten.
4. Keine Ruecknahme der C10d-Immutability-Regeln.
