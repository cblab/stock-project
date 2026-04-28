# v0.5 Evidence Engine Closure Audit

## 1. Scope

### What v0.5 can do

- map truth-layer trade data into `EvidenceTradeSample`
- extract terminal campaigns and trade outcomes
- evaluate eligibility conservatively
- aggregate by entry and exit context
- derive qualitative confidence from sample count
- build a neutral, machine-readable readout

### What v0.5 cannot do

- no DB-level snapshot validation for `eligible_full`
- no recommendations
- no buy/sell decisions
- no UI or API exposure
- no persisted readouts
- no forward-return analytics layer

## 2. Implemented Chunks

- **C1 Read Models**
  - Evidence DTOs and supporting value objects
- **C2 TradeOutcomeExtractor**
  - truth layer -> `EvidenceTradeSample`
- **C3 Eligibility Evaluator**
  - `eligible_full`, `eligible_outcome_only`, `excluded`
- **C4 EntryEvidenceAggregator**
  - aggregation by entry context
- **C5 ExitEvidenceAggregator**
  - aggregation by exit context
- **C6 ConfidenceCalculator**
  - qualitative confidence from `n`
- **C7 EvidenceReadoutBuilder**
  - neutral readout over entry and exit buckets
- **C8 Validation Fixtures**
  - deterministic Evidence test fixtures
- **C9 Snapshot Validation Foundation**
  - run provenance and availability fields for future DB-level snapshot validation

## 3. Snapshot Validation Gate

### Table reality

Relevant snapshot tables:

- `instrument_buy_signal_snapshot`
- `instrument_sepa_snapshot`
- `instrument_epa_snapshot`

Relevant truth-layer tables:

- `trade_event`
- `trade_campaign`
- `trade_migration_log`

Existing run tracking:

- `pipeline_run`
- `pipeline_run_item`

### Available snapshot fields before C9

All three snapshot tables had:

- `id`
- `instrument_id`
- `as_of_date`
- `created_at`
- `updated_at`

They did **not** have:

- `available_at`
- `snapshot_at`
- `finalized_at`
- `source_run_id`
- a run completion guarantee on the row itself

### What `trade_event` stores

`trade_event` stores:

- `buy_signal_snapshot_id`
- `sepa_snapshot_id`
- `epa_snapshot_id`
- `instrument_id`
- `event_timestamp`
- `scoring_version`
- `policy_version`
- `model_version`
- `macro_version`

`trade_campaign` does not store buy/SEPA/EPA snapshot IDs. For entry evidence, the relevant snapshot linkage lives on `trade_event`.

### Anti-hindsight checkability

Already checkable:

1. snapshot ID exists or not
2. snapshot row exists
3. snapshot `instrument_id` can be matched to `trade_event.instrument_id`
4. snapshot has an `as_of_date`

Not safely checkable before C9:

1. exact availability time of the snapshot
2. whether the snapshot belonged to a completed run
3. whether the snapshot was already final before the entry event timestamp
4. whether same-day rows were written before or after the event

`TradeSnapshotResolver` is intentionally conservative and only uses:

- matching `instrument_id`
- `as_of_date < DATE(event_timestamp)`

That is a day-level guard, not full anti-hindsight validation.

### Current gate decision

For the **current stored data**, the safe decision remains **Option B**:

- snapshot IDs present but not DB-validated -> `eligible_outcome_only`
- missing snapshots -> `eligible_outcome_only`
- `eligible_full` stays blocked in practice
- `EvidenceReadoutBuilder` should continue to warn `no_full_entry_evidence`

This remains the correct v0.5 runtime behavior.

## 4. Current Eligibility Semantics

### `eligible_full`

Meaning:

- trade outcome is valid
- snapshot context is fully validated against anti-hindsight rules

Current v0.5 state:

- reserved in the model
- not safely unlocked against the current DB shape

### `eligible_outcome_only`

Meaning:

- trade outcome is usable
- snapshot context is missing, seed-based, or not anti-hindsight validated

This is currently the normal conservative path for real samples.

### `excluded`

Meaning:

- non-terminal campaign state
- missing `closed_at`
- missing PnL
- invalid time order
- unknown campaign status

### Why `outcome_only` is conservative

The engine must not treat a snapshot as historical evidence unless it can prove:

1. the row existed
2. the row belonged to the same instrument
3. the row was already available before the entry event
4. the producing run was complete

Before C9, those proofs were incomplete. Therefore `eligible_outcome_only` is the safe default.

## 5. Known Limitations

- no active DB-level snapshot validation for `eligible_full`
- no forward returns as evidence eligibility input
- no recommendations
- no UI
- no DB persistence of readouts
- decimal/float follow-up remains open, especially around neutral thresholds and ratio formatting
- snapshot version provenance is still incomplete on the snapshot rows themselves

## 6. Path to Signal Evidence

### C9 design choice

For the foundation layer, the project should **reuse `pipeline_run`** instead of introducing a second `evidence_snapshot_run` table.

Why this fits better:

- `pipeline_run` already exists and is the canonical local run record
- it already has `status`, `started_at`, `finished_at`, and `exit_code`
- buy-signal backfill already derives historical snapshot dates from `pipeline_run`
- SEPA/EPA refresh jobs already use `pipeline_run` as lightweight tracking state

Creating a second run table would duplicate semantics that the project already has.

### Minimal foundation fields

Each snapshot table needs nullable provenance fields:

- `source_run_id` -> FK to `pipeline_run.id`
- `available_at` -> the timestamp from which the snapshot is safe to use for anti-hindsight validation

C9 adds these foundation columns to all three snapshot tables:

- `instrument_buy_signal_snapshot`
- `instrument_sepa_snapshot`
- `instrument_epa_snapshot`

Doctrine mapping status in the current codebase:

- `instrument_sepa_snapshot` has a Doctrine entity mapping
- `instrument_epa_snapshot` has a Doctrine entity mapping
- `instrument_buy_signal_snapshot` is currently DBAL/table-only and has no Doctrine entity in this codebase

That buy-signal asymmetry is intentional for now at the ORM layer, but the migration still adds `source_run_id` and `available_at` there as well so future DB-level validation can treat all three snapshot families consistently.

These fields are intentionally nullable:

- no backfill is required for old rows
- existing snapshots are **not** retroactively treated as validated
- runtime behavior does not change yet

### Why `available_at` matters

`as_of_date` alone is too weak.

To support future statements such as:

`SEPA >= 75 and EPA >= 70 had n = X, winRate = Y`

the engine must later prove that every referenced snapshot was already available before the entry event. `available_at` is the minimum field that makes that possible.

### When `eligible_full` becomes allowed

`eligible_full` should only be unlocked once the validator can prove all of the following:

1. snapshot ID is present
2. snapshot row exists
3. snapshot `instrument_id` matches the trade sample
4. `available_at` is not null
5. `available_at <= entry event timestamp`
6. `source_run_id` is present
7. the linked `pipeline_run` is in a completed/success state

If any of these checks fail, the sample must remain `eligible_outcome_only`.

### Remaining v0.6 work after C9

C9 only creates the foundation. v0.6 still needs:

- snapshot writers to populate `source_run_id`
- snapshot writers to populate `available_at`
- DB-level validation service using those fields
- targeted tests that prove real `eligible_full` samples
- only then: signal-bucket evidence such as `SEPA >= 75` / `EPA >= 70`

## 7. Required Follow-ups for v0.6

- implement DB-level snapshot validation against `source_run_id` and `available_at`
- decide whether snapshot row version fields are also required for future audits
- expose readout later via CLI/UI/API if wanted
- optionally centralize warning codes
- harden decimal/metric precision
- add explicit `eligible_full` tests once real validated samples can exist

## 8. Test Evidence

Relevant test commands for the evidence engine:

```powershell
.\tools\dev\test-web.ps1 tests/Service/Evidence/EvidenceEligibilityEvaluatorTest.php
.\tools\dev\test-web.ps1 tests/Service/Evidence/EntryEvidenceAggregatorTest.php
.\tools\dev\test-web.ps1 tests/Service/Evidence/ExitEvidenceAggregatorTest.php
.\tools\dev\test-web.ps1 tests/Service/Evidence/EvidenceReadoutBuilderTest.php
.\tools\dev\test-web.ps1 tests/Service/Evidence/Fixture/EvidenceTradeSampleFixtureTest.php
```

For the C9 audit/foundation chunk itself:

- if only docs change, no PHPUnit run is required
- if PHP classes or entities change, targeted evidence tests should be rerun
- if a migration is added, inspect config only; do not run production migrations during the audit
