<?php

declare(strict_types=1);

namespace App\Service\Evidence;

use App\Service\Evidence\Model\SnapshotValidationResult;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;

/**
 * DB-level snapshot validation service.
 *
 * Validates snapshots against the 11-point rule set:
 * 1. snapshot_id is not NULL
 * 2. snapshot row exists
 * 3. snapshot.instrument_id = expected instrument_id
 * 4. snapshot.available_at IS NOT NULL
 * 5. snapshot.available_at <= entry_timestamp
 * 6. snapshot.source_run_id IS NOT NULL
 * 7. pipeline_run.id = snapshot.source_run_id
 * 8. pipeline_run.status = 'success'
 * 9. pipeline_run.exit_code = 0
 * 10. pipeline_run.finished_at IS NOT NULL
 * 11. snapshot.available_at >= pipeline_run.finished_at (anti-hindsight)
 */
final readonly class SnapshotValidationService
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    /**
     * Validate a buy_signal snapshot for entry evidence.
     *
     * Uses direct DBAL query against instrument_buy_signal_snapshot table.
     */
    public function validateBuySignalSnapshot(
        ?int $snapshotId,
        int $expectedInstrumentId,
        DateTimeImmutable $entryTimestamp,
    ): SnapshotValidationResult {
        return $this->validateSnapshot(
            $snapshotId,
            $expectedInstrumentId,
            $entryTimestamp,
            'instrument_buy_signal_snapshot',
        );
    }

    /**
     * Validate a SEPA snapshot for entry evidence.
     */
    public function validateSepaSnapshot(
        ?int $snapshotId,
        int $expectedInstrumentId,
        DateTimeImmutable $entryTimestamp,
    ): SnapshotValidationResult {
        return $this->validateSnapshot(
            $snapshotId,
            $expectedInstrumentId,
            $entryTimestamp,
            'instrument_sepa_snapshot',
        );
    }

    /**
     * Validate an EPA snapshot for entry evidence.
     */
    public function validateEpaSnapshot(
        ?int $snapshotId,
        int $expectedInstrumentId,
        DateTimeImmutable $entryTimestamp,
    ): SnapshotValidationResult {
        return $this->validateSnapshot(
            $snapshotId,
            $expectedInstrumentId,
            $entryTimestamp,
            'instrument_epa_snapshot',
        );
    }

    /**
     * Core validation logic used by all snapshot families.
     */
    private function validateSnapshot(
        ?int $snapshotId,
        int $expectedInstrumentId,
        DateTimeImmutable $entryTimestamp,
        string $snapshotTable,
    ): SnapshotValidationResult {
        // Rule 1: snapshot_id is not NULL
        if ($snapshotId === null) {
            return SnapshotValidationResult::invalid('missing_snapshot_id');
        }

        // Fetch snapshot with pipeline_run data via JOIN
        $sql = <<<SQL
            SELECT 
                s.id AS snapshot_id,
                s.instrument_id AS snapshot_instrument_id,
                s.available_at AS snapshot_available_at,
                s.source_run_id AS snapshot_source_run_id,
                pr.id AS pipeline_run_id,
                pr.status AS pipeline_run_status,
                pr.exit_code AS pipeline_run_exit_code,
                pr.finished_at AS pipeline_run_finished_at
            FROM {$snapshotTable} s
            LEFT JOIN pipeline_run pr ON pr.id = s.source_run_id
            WHERE s.id = :snapshotId
            SQL;

        $row = $this->connection->fetchAssociative($sql, ['snapshotId' => $snapshotId]);

        // Rule 2: snapshot row exists
        if ($row === false) {
            return SnapshotValidationResult::invalid('snapshot_not_found', [
                'snapshot_id' => $snapshotId,
                'table' => $snapshotTable,
            ]);
        }

        // Rule 3: snapshot.instrument_id matches expected
        $snapshotInstrumentId = (int) $row['snapshot_instrument_id'];
        if ($snapshotInstrumentId !== $expectedInstrumentId) {
            return SnapshotValidationResult::invalid('instrument_mismatch', [
                'snapshot_id' => $snapshotId,
                'expected_instrument_id' => $expectedInstrumentId,
                'actual_instrument_id' => $snapshotInstrumentId,
            ]);
        }

        // Rule 4: snapshot.available_at IS NOT NULL
        if ($row['snapshot_available_at'] === null) {
            return SnapshotValidationResult::invalid('missing_available_at', [
                'snapshot_id' => $snapshotId,
            ]);
        }

        // Rule 5: snapshot.available_at <= entry_timestamp
        $availableAt = new DateTimeImmutable($row['snapshot_available_at']);
        if ($availableAt > $entryTimestamp) {
            return SnapshotValidationResult::invalid('snapshot_after_entry', [
                'snapshot_id' => $snapshotId,
                'available_at' => $availableAt->format('Y-m-d H:i:s'),
                'entry_timestamp' => $entryTimestamp->format('Y-m-d H:i:s'),
            ]);
        }

        // Rule 6: snapshot.source_run_id IS NOT NULL
        if ($row['snapshot_source_run_id'] === null) {
            return SnapshotValidationResult::invalid('missing_source_run', [
                'snapshot_id' => $snapshotId,
            ]);
        }

        // Rule 7: pipeline_run.id = snapshot.source_run_id (implied by JOIN)
        // If pipeline_run_id is NULL, the LEFT JOIN didn't find a match
        if ($row['pipeline_run_id'] === null) {
            return SnapshotValidationResult::invalid('source_run_not_found', [
                'snapshot_id' => $snapshotId,
                'source_run_id' => (int) $row['snapshot_source_run_id'],
            ]);
        }

        // Rule 8: pipeline_run.status = 'success'
        if ($row['pipeline_run_status'] !== 'success') {
            return SnapshotValidationResult::invalid('source_run_not_success', [
                'snapshot_id' => $snapshotId,
                'source_run_id' => (int) $row['snapshot_source_run_id'],
                'actual_status' => $row['pipeline_run_status'],
            ]);
        }

        // Rule 9: pipeline_run.exit_code = 0
        $exitCode = $row['pipeline_run_exit_code'] !== null ? (int) $row['pipeline_run_exit_code'] : null;
        if ($exitCode !== 0) {
            return SnapshotValidationResult::invalid('source_run_exit_code_nonzero', [
                'snapshot_id' => $snapshotId,
                'source_run_id' => (int) $row['snapshot_source_run_id'],
                'actual_exit_code' => $exitCode,
            ]);
        }

        // Rule 10: pipeline_run.finished_at IS NOT NULL
        if ($row['pipeline_run_finished_at'] === null) {
            return SnapshotValidationResult::invalid('source_run_missing_finished_at', [
                'snapshot_id' => $snapshotId,
                'source_run_id' => (int) $row['snapshot_source_run_id'],
            ]);
        }

        // Rule 11: snapshot.available_at >= pipeline_run.finished_at
        // Anti-hindsight: snapshot cannot be "available" before the pipeline run actually finished
        $finishedAt = new DateTimeImmutable($row['pipeline_run_finished_at']);
        if ($availableAt < $finishedAt) {
            return SnapshotValidationResult::invalid('available_at_before_run_finished_at', [
                'snapshot_id' => $snapshotId,
                'source_run_id' => (int) $row['snapshot_source_run_id'],
                'available_at' => $availableAt->format('Y-m-d H:i:s'),
                'pipeline_run_finished_at' => $finishedAt->format('Y-m-d H:i:s'),
            ]);
        }

        return SnapshotValidationResult::valid();
    }
}
