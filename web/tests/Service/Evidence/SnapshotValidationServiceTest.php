<?php

declare(strict_types=1);

namespace App\Tests\Service\Evidence;

use App\Service\Evidence\SnapshotValidationService;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;

/**
 * Tests for SnapshotValidationService.
 *
 * @group evidence
 * @group snapshot
 */
final class SnapshotValidationServiceTest extends TestCase
{
    private Connection $connection;
    private SnapshotValidationService $service;

    protected function setUp(): void
    {
        // Use SQLite in-memory for isolated testing
        $this->connection = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'memory' => true,
        ]);

        $this->createSchema();
        $this->service = new SnapshotValidationService($this->connection);
    }

    private function createSchema(): void
    {
        // Create pipeline_run table
        $this->connection->executeStatement(<<<'SQL'
            CREATE TABLE pipeline_run (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                run_id VARCHAR(64) NOT NULL,
                run_key VARCHAR(64) NOT NULL,
                status VARCHAR(32) NOT NULL,
                run_scope VARCHAR(32) NOT NULL DEFAULT 'portfolio',
                run_path VARCHAR(1024) NOT NULL DEFAULT '',
                started_at DATETIME DEFAULT NULL,
                finished_at DATETIME DEFAULT NULL,
                exit_code INTEGER DEFAULT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
            SQL);

        // Create instrument_buy_signal_snapshot table
        $this->connection->executeStatement(<<<'SQL'
            CREATE TABLE instrument_buy_signal_snapshot (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                instrument_id INTEGER NOT NULL,
                as_of_date DATE NOT NULL,
                kronos_score REAL NOT NULL DEFAULT 0,
                sentiment_score REAL NOT NULL DEFAULT 0,
                merged_score REAL NOT NULL DEFAULT 0,
                decision VARCHAR(16) NOT NULL DEFAULT 'HOLD',
                sentiment_label VARCHAR(32) DEFAULT NULL,
                kronos_raw_score REAL DEFAULT NULL,
                sentiment_raw_score REAL DEFAULT NULL,
                detail_json TEXT DEFAULT NULL,
                forward_return_5d REAL DEFAULT NULL,
                forward_return_20d REAL DEFAULT NULL,
                forward_return_60d REAL DEFAULT NULL,
                source_run_id INTEGER DEFAULT NULL,
                available_at DATETIME DEFAULT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
            SQL);

        // Create instrument_sepa_snapshot table
        $this->connection->executeStatement(<<<'SQL'
            CREATE TABLE instrument_sepa_snapshot (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                instrument_id INTEGER NOT NULL,
                as_of_date DATE NOT NULL,
                market_score REAL NOT NULL DEFAULT 0,
                stage_score REAL NOT NULL DEFAULT 0,
                relative_strength_score REAL NOT NULL DEFAULT 0,
                base_quality_score REAL NOT NULL DEFAULT 0,
                volume_score REAL NOT NULL DEFAULT 0,
                momentum_score REAL NOT NULL DEFAULT 0,
                risk_score REAL NOT NULL DEFAULT 0,
                superperformance_score REAL NOT NULL DEFAULT 0,
                vcp_score REAL NOT NULL DEFAULT 0,
                microstructure_score REAL NOT NULL DEFAULT 0,
                breakout_readiness_score REAL NOT NULL DEFAULT 0,
                structure_score REAL NOT NULL DEFAULT 0,
                execution_score REAL NOT NULL DEFAULT 0,
                total_score REAL NOT NULL DEFAULT 0,
                traffic_light VARCHAR(16) NOT NULL DEFAULT 'Rot',
                kill_triggers_json TEXT DEFAULT NULL,
                detail_json TEXT DEFAULT NULL,
                forward_return_5d REAL DEFAULT NULL,
                forward_return_20d REAL DEFAULT NULL,
                forward_return_60d REAL DEFAULT NULL,
                source_run_id INTEGER DEFAULT NULL,
                available_at DATETIME DEFAULT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
            SQL);

        // Create instrument_epa_snapshot table
        $this->connection->executeStatement(<<<'SQL'
            CREATE TABLE instrument_epa_snapshot (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                instrument_id INTEGER NOT NULL,
                as_of_date DATE NOT NULL,
                failure_score REAL NOT NULL DEFAULT 0,
                trend_exit_score REAL NOT NULL DEFAULT 0,
                climax_score REAL NOT NULL DEFAULT 0,
                risk_score REAL NOT NULL DEFAULT 0,
                total_score REAL NOT NULL DEFAULT 0,
                action VARCHAR(24) NOT NULL DEFAULT 'HOLD',
                hard_triggers_json TEXT DEFAULT NULL,
                soft_warnings_json TEXT DEFAULT NULL,
                detail_json TEXT DEFAULT NULL,
                source_run_id INTEGER DEFAULT NULL,
                available_at DATETIME DEFAULT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
            SQL);
    }

    // =========================================================================
    // POSITIVE TESTS
    // =========================================================================

    public function testValidSepaSnapshot(): void
    {
        $this->insertPipelineRun(1, 'success', 0, '2024-01-10 12:00:00');
        $this->insertSepaSnapshot(100, 1, 1, '2024-01-10 12:00:00');

        $entryTimestamp = new DateTimeImmutable('2024-01-15 10:00:00');
        $result = $this->service->validateSepaSnapshot(100, 1, $entryTimestamp);

        self::assertTrue($result->isValid());
        self::assertNull($result->reasonCode());
    }

    public function testValidEpaSnapshot(): void
    {
        $this->insertPipelineRun(1, 'success', 0, '2024-01-10 12:00:00');
        $this->insertEpaSnapshot(200, 1, 1, '2024-01-10 12:00:00');

        $entryTimestamp = new DateTimeImmutable('2024-01-15 10:00:00');
        $result = $this->service->validateEpaSnapshot(200, 1, $entryTimestamp);

        self::assertTrue($result->isValid());
        self::assertNull($result->reasonCode());
    }

    public function testValidBuySignalSnapshot(): void
    {
        $this->insertPipelineRun(1, 'success', 0, '2024-01-10 12:00:00');
        $this->insertBuySignalSnapshot(300, 1, 1, '2024-01-10 12:00:00');

        $entryTimestamp = new DateTimeImmutable('2024-01-15 10:00:00');
        $result = $this->service->validateBuySignalSnapshot(300, 1, $entryTimestamp);

        self::assertTrue($result->isValid());
        self::assertNull($result->reasonCode());
    }

    public function testMatchingInstrumentId(): void
    {
        $this->insertPipelineRun(1, 'success', 0, '2024-01-10 12:00:00');
        $this->insertSepaSnapshot(100, 42, 1, '2024-01-10 12:00:00');

        $entryTimestamp = new DateTimeImmutable('2024-01-15 10:00:00');
        $result = $this->service->validateSepaSnapshot(100, 42, $entryTimestamp);

        self::assertTrue($result->isValid());
    }

    public function testAvailableAtEqualsEntryTimestamp(): void
    {
        // available_at == entry_timestamp should be valid (<=)
        $this->insertPipelineRun(1, 'success', 0, '2024-01-10 12:00:00');
        $this->insertSepaSnapshot(100, 1, 1, '2024-01-15 10:00:00');

        $entryTimestamp = new DateTimeImmutable('2024-01-15 10:00:00');
        $result = $this->service->validateSepaSnapshot(100, 1, $entryTimestamp);

        self::assertTrue($result->isValid());
    }

    public function testAvailableAtBeforeEntryTimestamp(): void
    {
        $this->insertPipelineRun(1, 'success', 0, '2024-01-10 12:00:00');
        $this->insertSepaSnapshot(100, 1, 1, '2024-01-08 10:00:00');

        $entryTimestamp = new DateTimeImmutable('2024-01-15 10:00:00');
        $result = $this->service->validateSepaSnapshot(100, 1, $entryTimestamp);

        self::assertTrue($result->isValid());
    }

    public function testSourceRunSuccess(): void
    {
        $this->insertPipelineRun(1, 'success', 0, '2024-01-10 12:00:00');
        $this->insertSepaSnapshot(100, 1, 1, '2024-01-10 12:00:00');

        $entryTimestamp = new DateTimeImmutable('2024-01-15 10:00:00');
        $result = $this->service->validateSepaSnapshot(100, 1, $entryTimestamp);

        self::assertTrue($result->isValid());
    }

    public function testExitCodeZero(): void
    {
        $this->insertPipelineRun(1, 'success', 0, '2024-01-10 12:00:00');
        $this->insertSepaSnapshot(100, 1, 1, '2024-01-10 12:00:00');

        $entryTimestamp = new DateTimeImmutable('2024-01-15 10:00:00');
        $result = $this->service->validateSepaSnapshot(100, 1, $entryTimestamp);

        self::assertTrue($result->isValid());
    }

    public function testFinishedAtPresent(): void
    {
        $this->insertPipelineRun(1, 'success', 0, '2024-01-10 12:00:00');
        $this->insertSepaSnapshot(100, 1, 1, '2024-01-10 12:00:00');

        $entryTimestamp = new DateTimeImmutable('2024-01-15 10:00:00');
        $result = $this->service->validateSepaSnapshot(100, 1, $entryTimestamp);

        self::assertTrue($result->isValid());
    }

    // =========================================================================
    // NEGATIVE TESTS
    // =========================================================================

    public function testMissingSnapshotId(): void
    {
        $entryTimestamp = new DateTimeImmutable('2024-01-15 10:00:00');
        $result = $this->service->validateSepaSnapshot(null, 1, $entryTimestamp);

        self::assertFalse($result->isValid());
        self::assertSame('missing_snapshot_id', $result->reasonCode());
    }

    public function testSnapshotNotFound(): void
    {
        $entryTimestamp = new DateTimeImmutable('2024-01-15 10:00:00');
        $result = $this->service->validateSepaSnapshot(9999, 1, $entryTimestamp);

        self::assertFalse($result->isValid());
        self::assertSame('snapshot_not_found', $result->reasonCode());
    }

    public function testInstrumentMismatch(): void
    {
        $this->insertPipelineRun(1, 'success', 0, '2024-01-10 12:00:00');
        $this->insertSepaSnapshot(100, 99, 1, '2024-01-10 12:00:00'); // instrument_id = 99

        $entryTimestamp = new DateTimeImmutable('2024-01-15 10:00:00');
        $result = $this->service->validateSepaSnapshot(100, 1, $entryTimestamp); // expecting instrument_id = 1

        self::assertFalse($result->isValid());
        self::assertSame('instrument_mismatch', $result->reasonCode());
        self::assertSame(1, $result->details()['expected_instrument_id']);
        self::assertSame(99, $result->details()['actual_instrument_id']);
    }

    public function testMissingAvailableAt(): void
    {
        $this->insertPipelineRun(1, 'success', 0, '2024-01-10 12:00:00');
        $this->insertSepaSnapshot(100, 1, 1, null); // available_at is NULL

        $entryTimestamp = new DateTimeImmutable('2024-01-15 10:00:00');
        $result = $this->service->validateSepaSnapshot(100, 1, $entryTimestamp);

        self::assertFalse($result->isValid());
        self::assertSame('missing_available_at', $result->reasonCode());
    }

    public function testSnapshotAfterEntry(): void
    {
        $this->insertPipelineRun(1, 'success', 0, '2024-01-20 12:00:00');
        $this->insertSepaSnapshot(100, 1, 1, '2024-01-20 12:00:00'); // available AFTER entry

        $entryTimestamp = new DateTimeImmutable('2024-01-15 10:00:00'); // entry is BEFORE available
        $result = $this->service->validateSepaSnapshot(100, 1, $entryTimestamp);

        self::assertFalse($result->isValid());
        self::assertSame('snapshot_after_entry', $result->reasonCode());
    }

    public function testMissingSourceRun(): void
    {
        $this->insertSepaSnapshot(100, 1, null, '2024-01-10 12:00:00'); // source_run_id is NULL

        $entryTimestamp = new DateTimeImmutable('2024-01-15 10:00:00');
        $result = $this->service->validateSepaSnapshot(100, 1, $entryTimestamp);

        self::assertFalse($result->isValid());
        self::assertSame('missing_source_run', $result->reasonCode());
    }

    public function testSourceRunNotFound(): void
    {
        $this->insertSepaSnapshot(100, 1, 9999, '2024-01-10 12:00:00'); // source_run_id references non-existent run

        $entryTimestamp = new DateTimeImmutable('2024-01-15 10:00:00');
        $result = $this->service->validateSepaSnapshot(100, 1, $entryTimestamp);

        self::assertFalse($result->isValid());
        self::assertSame('source_run_not_found', $result->reasonCode());
    }

    public function testSourceRunNotSuccess(): void
    {
        $this->insertPipelineRun(1, 'failed', 1, '2024-01-10 12:00:00'); // status = failed
        $this->insertSepaSnapshot(100, 1, 1, '2024-01-10 12:00:00');

        $entryTimestamp = new DateTimeImmutable('2024-01-15 10:00:00');
        $result = $this->service->validateSepaSnapshot(100, 1, $entryTimestamp);

        self::assertFalse($result->isValid());
        self::assertSame('source_run_not_success', $result->reasonCode());
        self::assertSame('failed', $result->details()['actual_status']);
    }

    public function testSourceRunExitCodeNonzero(): void
    {
        $this->insertPipelineRun(1, 'success', 1, '2024-01-10 12:00:00'); // exit_code = 1 (non-zero)
        $this->insertSepaSnapshot(100, 1, 1, '2024-01-10 12:00:00');

        $entryTimestamp = new DateTimeImmutable('2024-01-15 10:00:00');
        $result = $this->service->validateSepaSnapshot(100, 1, $entryTimestamp);

        self::assertFalse($result->isValid());
        self::assertSame('source_run_exit_code_nonzero', $result->reasonCode());
        self::assertSame(1, $result->details()['actual_exit_code']);
    }

    public function testSourceRunMissingFinishedAt(): void
    {
        $this->insertPipelineRunWithoutFinishedAt(1, 'success', 0); // finished_at is NULL
        $this->insertSepaSnapshot(100, 1, 1, '2024-01-10 12:00:00');

        $entryTimestamp = new DateTimeImmutable('2024-01-15 10:00:00');
        $result = $this->service->validateSepaSnapshot(100, 1, $entryTimestamp);

        self::assertFalse($result->isValid());
        self::assertSame('source_run_missing_finished_at', $result->reasonCode());
    }

    // =========================================================================
    // HELPER METHODS
    // =========================================================================

    private function insertPipelineRun(int $id, string $status, int $exitCode, string $finishedAt): void
    {
        $this->connection->executeStatement(
            'INSERT INTO pipeline_run (id, run_id, run_key, status, run_scope, run_path, finished_at, exit_code) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [$id, "run_$id", "run_$id", $status, 'portfolio', '/test', $finishedAt, $exitCode]
        );
    }

    private function insertPipelineRunWithoutFinishedAt(int $id, string $status, int $exitCode): void
    {
        $this->connection->executeStatement(
            'INSERT INTO pipeline_run (id, run_id, run_key, status, run_scope, run_path, finished_at, exit_code) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [$id, "run_$id", "run_$id", $status, 'portfolio', '/test', null, $exitCode]
        );
    }

    private function insertSepaSnapshot(int $id, int $instrumentId, ?int $sourceRunId, ?string $availableAt): void
    {
        $this->connection->executeStatement(
            'INSERT INTO instrument_sepa_snapshot (id, instrument_id, as_of_date, source_run_id, available_at) VALUES (?, ?, ?, ?, ?)',
            [$id, $instrumentId, '2024-01-10', $sourceRunId, $availableAt]
        );
    }

    private function insertEpaSnapshot(int $id, int $instrumentId, ?int $sourceRunId, ?string $availableAt): void
    {
        $this->connection->executeStatement(
            'INSERT INTO instrument_epa_snapshot (id, instrument_id, as_of_date, source_run_id, available_at) VALUES (?, ?, ?, ?, ?)',
            [$id, $instrumentId, '2024-01-10', $sourceRunId, $availableAt]
        );
    }

    private function insertBuySignalSnapshot(int $id, int $instrumentId, ?int $sourceRunId, ?string $availableAt): void
    {
        $this->connection->executeStatement(
            'INSERT INTO instrument_buy_signal_snapshot (id, instrument_id, as_of_date, source_run_id, available_at) VALUES (?, ?, ?, ?, ?)',
            [$id, $instrumentId, '2024-01-10', $sourceRunId, $availableAt]
        );
    }
}