<?php

declare(strict_types=1);

namespace App\Tests\Service\Evidence;

use App\Service\Evidence\EvidenceEligibilityEvaluator;
use App\Service\Evidence\SnapshotValidationService;
use App\Service\Evidence\Model\EvidenceDataQualityFlag;
use App\Service\Evidence\Model\EvidenceEligibilityStatus;
use App\Service\Evidence\Model\EvidenceExclusionReason;
use App\Service\Evidence\Model\EvidenceTradeSample;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;

final class EvidenceEligibilityEvaluatorTest extends TestCase
{
    private EvidenceEligibilityEvaluator $evaluator;

    protected function setUp(): void
    {
        $this->evaluator = new EvidenceEligibilityEvaluator();
    }

    public function testTerminalClosedProfitWithoutSnapshotIsOutcomeOnly(): void
    {
        $sample = $this->createSample([
            'campaignState' => 'closed_profit',
            'realizedPnlPct' => '0.15',
            'seedSource' => 'live',
            'buySignalSnapshotId' => null,
            'sepaSnapshotId' => null,
            'epaSnapshotId' => null,
        ]);

        $result = $this->evaluator->evaluateTradeSample($sample);

        $this->assertEquals(EvidenceEligibilityStatus::eligibleOutcomeOnly(), $result->status);
        $this->assertNull($result->exclusionReason);
        $this->assertContainsEquals(EvidenceDataQualityFlag::missingEntrySnapshot(), $result->dataQualityFlags);
        $this->assertContainsEquals(EvidenceDataQualityFlag::snapshotIncomplete(), $result->dataQualityFlags);
    }

    public function testMigrationSeedIsOutcomeOnlyWithFlags(): void
    {
        $sample = $this->createSample([
            'campaignState' => 'closed_profit',
            'realizedPnlPct' => '0.10',
            'seedSource' => 'migration',
            'buySignalSnapshotId' => 123,
        ]);

        $result = $this->evaluator->evaluateTradeSample($sample);

        $this->assertEquals(EvidenceEligibilityStatus::eligibleOutcomeOnly(), $result->status);
        $this->assertNull($result->exclusionReason);
        $this->assertContainsEquals(EvidenceDataQualityFlag::migrationSeed(), $result->dataQualityFlags);
        $this->assertContainsEquals(EvidenceDataQualityFlag::containsSeedData(), $result->dataQualityFlags);
    }

    public function testManualSeedIsOutcomeOnlyWithFlags(): void
    {
        $sample = $this->createSample([
            'campaignState' => 'closed_loss',
            'realizedPnlPct' => '-0.08',
            'seedSource' => 'manual',
        ]);

        $result = $this->evaluator->evaluateTradeSample($sample);

        $this->assertEquals(EvidenceEligibilityStatus::eligibleOutcomeOnly(), $result->status);
        $this->assertNull($result->exclusionReason);
        $this->assertContainsEquals(EvidenceDataQualityFlag::manualSeed(), $result->dataQualityFlags);
        $this->assertContainsEquals(EvidenceDataQualityFlag::containsSeedData(), $result->dataQualityFlags);
    }

    public function testInvalidTimeOrderIsExcluded(): void
    {
        $sample = $this->createSample([
            'campaignState' => 'closed_profit',
            'openedAt' => new DateTimeImmutable('2024-01-15 10:00:00'),
            'closedAt' => new DateTimeImmutable('2024-01-10 10:00:00'),
            'realizedPnlPct' => '0.05',
            'seedSource' => 'live',
        ]);

        $result = $this->evaluator->evaluateTradeSample($sample);

        $this->assertEquals(EvidenceEligibilityStatus::excluded(), $result->status);
        $this->assertEquals(EvidenceExclusionReason::invalidTimeOrder(), $result->exclusionReason);
    }

    public function testMissingPnlIsExcluded(): void
    {
        $sample = $this->createSample([
            'campaignState' => 'closed_profit',
            'realizedPnlPct' => null,
            'seedSource' => 'live',
        ]);

        $result = $this->evaluator->evaluateTradeSample($sample);

        $this->assertEquals(EvidenceEligibilityStatus::excluded(), $result->status);
        $this->assertEquals(EvidenceExclusionReason::missingPnl(), $result->exclusionReason);
    }

    public function testNonTerminalOpenCampaignIsExcluded(): void
    {
        $sample = $this->createSample(['campaignState' => 'open', 'closedAt' => null]);
        $result = $this->evaluator->evaluateTradeSample($sample);
        $this->assertEquals(EvidenceEligibilityStatus::excluded(), $result->status);
        $this->assertEquals(EvidenceExclusionReason::openCampaign(), $result->exclusionReason);
    }

    public function testNonTerminalTrimmedCampaignIsExcluded(): void
    {
        $sample = $this->createSample(['campaignState' => 'trimmed', 'realizedPnlPct' => '0.05']);
        $result = $this->evaluator->evaluateTradeSample($sample);
        $this->assertEquals(EvidenceEligibilityStatus::excluded(), $result->status);
        $this->assertEquals(EvidenceExclusionReason::openCampaign(), $result->exclusionReason);
    }

    public function testNonTerminalPausedCampaignIsExcluded(): void
    {
        $sample = $this->createSample(['campaignState' => 'paused', 'realizedPnlPct' => '-0.02']);
        $result = $this->evaluator->evaluateTradeSample($sample);
        $this->assertEquals(EvidenceEligibilityStatus::excluded(), $result->status);
        $this->assertEquals(EvidenceExclusionReason::openCampaign(), $result->exclusionReason);
    }

    public function testUnvalidatedSnapshotIdsAreNotEligibleFull(): void
    {
        $sample = $this->createSample([
            'campaignState' => 'closed_profit',
            'realizedPnlPct' => '0.12',
            'seedSource' => 'live',
            'buySignalSnapshotId' => 123,
        ]);

        $result = $this->evaluator->evaluateTradeSample($sample);

        $this->assertNotEquals(EvidenceEligibilityStatus::eligibleFull(), $result->status);
        $this->assertContainsEquals(EvidenceDataQualityFlag::snapshotIncomplete(), $result->dataQualityFlags);
    }

    public function testMissingClosedAtIsExcluded(): void
    {
        $sample = $this->createSample([
            'campaignState' => 'closed_profit',
            'closedAt' => null,
            'realizedPnlPct' => '0.05',
        ]);

        $result = $this->evaluator->evaluateTradeSample($sample);

        $this->assertEquals(EvidenceEligibilityStatus::excluded(), $result->status);
        $this->assertEquals(EvidenceExclusionReason::missingClosedAt(), $result->exclusionReason);
    }

    public function testUnknownCampaignStateIsExcluded(): void
    {
        $sample = $this->createSample([
            'campaignState' => 'weird_state',
            'realizedPnlPct' => '0.10',
        ]);

        $result = $this->evaluator->evaluateTradeSample($sample);

        $this->assertEquals(EvidenceEligibilityStatus::excluded(), $result->status);
        $this->assertEquals(EvidenceExclusionReason::unknownState(), $result->exclusionReason);
    }

    public function testValidSnapshotCanProduceEligibleFull(): void
    {
        $evaluator = $this->createValidatedEvaluator(function (Connection $connection): void {
            $this->insertPipelineRun($connection, 1, 'success', 0, '2024-01-01 09:00:00');
            $this->insertSepaSnapshot($connection, 100, 1, 1, '2024-01-01 10:00:00');
        });

        $sample = $this->createSample([
            'openedAt' => new DateTimeImmutable('2024-01-01 12:00:00'),
            'sepaSnapshotId' => 100,
        ]);

        $result = $evaluator->evaluateTradeSample($sample);

        $this->assertEquals(EvidenceEligibilityStatus::eligibleFull(), $result->status);
        $this->assertSame([], $result->dataQualityFlags);
    }

    public function testInvalidBuySignalSnapshotDowngradesToOutcomeOnly(): void
    {
        $evaluator = $this->createValidatedEvaluator(function (Connection $connection): void {
            $this->insertPipelineRun($connection, 1, 'success', 0, '2024-01-01 09:00:00');
            $this->insertBuySignalSnapshot($connection, 300, 1, 1, '2024-01-01 13:00:00');
        });

        $sample = $this->createSample([
            'openedAt' => new DateTimeImmutable('2024-01-01 12:00:00'),
            'buySignalSnapshotId' => 300,
        ]);

        $result = $evaluator->evaluateTradeSample($sample);

        $this->assertEquals(EvidenceEligibilityStatus::eligibleOutcomeOnly(), $result->status);
        $this->assertContainsEquals(EvidenceDataQualityFlag::snapshotIncomplete(), $result->dataQualityFlags);
    }

    public function testInvalidSepaSnapshotDowngradesToOutcomeOnly(): void
    {
        $evaluator = $this->createValidatedEvaluator(function (Connection $connection): void {
            $this->insertPipelineRun($connection, 1, 'success', 0, '2024-01-01 09:00:00');
            $this->insertSepaSnapshot($connection, 100, 1, 1, '2024-01-01 13:00:00');
        });

        $sample = $this->createSample([
            'openedAt' => new DateTimeImmutable('2024-01-01 12:00:00'),
            'sepaSnapshotId' => 100,
        ]);

        $result = $evaluator->evaluateTradeSample($sample);

        $this->assertEquals(EvidenceEligibilityStatus::eligibleOutcomeOnly(), $result->status);
        $this->assertContainsEquals(EvidenceDataQualityFlag::snapshotIncomplete(), $result->dataQualityFlags);
    }

    public function testInvalidEpaSnapshotDowngradesToOutcomeOnly(): void
    {
        $evaluator = $this->createValidatedEvaluator(function (Connection $connection): void {
            $this->insertPipelineRun($connection, 1, 'success', 0, '2024-01-01 09:00:00');
            $this->insertEpaSnapshot($connection, 200, 1, 1, '2024-01-01 13:00:00');
        });

        $sample = $this->createSample([
            'openedAt' => new DateTimeImmutable('2024-01-01 12:00:00'),
            'epaSnapshotId' => 200,
        ]);

        $result = $evaluator->evaluateTradeSample($sample);

        $this->assertEquals(EvidenceEligibilityStatus::eligibleOutcomeOnly(), $result->status);
        $this->assertContainsEquals(EvidenceDataQualityFlag::snapshotIncomplete(), $result->dataQualityFlags);
    }

    public function testMixedValidAndInvalidSnapshotsDowngradesToOutcomeOnly(): void
    {
        $evaluator = $this->createValidatedEvaluator(function (Connection $connection): void {
            $this->insertPipelineRun($connection, 1, 'success', 0, '2024-01-01 09:00:00');
            $this->insertPipelineRun($connection, 2, 'success', 0, '2024-01-01 09:00:00');
            $this->insertPipelineRun($connection, 3, 'success', 0, '2024-01-01 09:00:00');
            $this->insertBuySignalSnapshot($connection, 300, 1, 1, '2024-01-01 10:00:00');
            $this->insertSepaSnapshot($connection, 100, 1, 2, '2024-01-01 10:00:00');
            $this->insertEpaSnapshot($connection, 200, 1, 3, '2024-01-01 13:00:00');
        });

        $sample = $this->createSample([
            'openedAt' => new DateTimeImmutable('2024-01-01 12:00:00'),
            'buySignalSnapshotId' => 300,
            'sepaSnapshotId' => 100,
            'epaSnapshotId' => 200,
        ]);

        $result = $evaluator->evaluateTradeSample($sample);

        $this->assertEquals(EvidenceEligibilityStatus::eligibleOutcomeOnly(), $result->status);
        $this->assertContainsEquals(EvidenceDataQualityFlag::snapshotIncomplete(), $result->dataQualityFlags);
    }

    public function testMigrationSeedWithValidSnapshotStaysOutcomeOnly(): void
    {
        $evaluator = $this->createValidatedEvaluator(function (Connection $connection): void {
            $this->insertPipelineRun($connection, 1, 'success', 0, '2024-01-01 09:00:00');
            $this->insertSepaSnapshot($connection, 100, 1, 1, '2024-01-01 10:00:00');
        });

        $sample = $this->createSample([
            'openedAt' => new DateTimeImmutable('2024-01-01 12:00:00'),
            'seedSource' => 'migration',
            'sepaSnapshotId' => 100,
        ]);

        $result = $evaluator->evaluateTradeSample($sample);

        $this->assertEquals(EvidenceEligibilityStatus::eligibleOutcomeOnly(), $result->status);
        $this->assertContainsEquals(EvidenceDataQualityFlag::migrationSeed(), $result->dataQualityFlags);
        $this->assertContainsEquals(EvidenceDataQualityFlag::containsSeedData(), $result->dataQualityFlags);
    }

    public function testManualSeedWithValidSnapshotStaysOutcomeOnly(): void
    {
        $evaluator = $this->createValidatedEvaluator(function (Connection $connection): void {
            $this->insertPipelineRun($connection, 1, 'success', 0, '2024-01-01 09:00:00');
            $this->insertSepaSnapshot($connection, 100, 1, 1, '2024-01-01 10:00:00');
        });

        $sample = $this->createSample([
            'openedAt' => new DateTimeImmutable('2024-01-01 12:00:00'),
            'seedSource' => 'manual',
            'sepaSnapshotId' => 100,
        ]);

        $result = $evaluator->evaluateTradeSample($sample);

        $this->assertEquals(EvidenceEligibilityStatus::eligibleOutcomeOnly(), $result->status);
        $this->assertContainsEquals(EvidenceDataQualityFlag::manualSeed(), $result->dataQualityFlags);
        $this->assertContainsEquals(EvidenceDataQualityFlag::containsSeedData(), $result->dataQualityFlags);
    }

    public function testNonTerminalCampaignWithValidSnapshotIsExcluded(): void
    {
        $evaluator = $this->createValidatedEvaluator(function (Connection $connection): void {
            $this->insertPipelineRun($connection, 1, 'success', 0, '2024-01-01 09:00:00');
            $this->insertSepaSnapshot($connection, 100, 1, 1, '2024-01-01 10:00:00');
        });

        $sample = $this->createSample([
            'campaignState' => 'open',
            'closedAt' => null,
            'openedAt' => new DateTimeImmutable('2024-01-01 12:00:00'),
            'sepaSnapshotId' => 100,
        ]);

        $result = $evaluator->evaluateTradeSample($sample);

        $this->assertEquals(EvidenceEligibilityStatus::excluded(), $result->status);
        $this->assertEquals(EvidenceExclusionReason::openCampaign(), $result->exclusionReason);
    }

    public function testInvalidTimeOrderWithValidSnapshotIsExcluded(): void
    {
        $evaluator = $this->createValidatedEvaluator(function (Connection $connection): void {
            $this->insertPipelineRun($connection, 1, 'success', 0, '2024-01-01 09:00:00');
            $this->insertSepaSnapshot($connection, 100, 1, 1, '2024-01-01 10:00:00');
        });

        $sample = $this->createSample([
            'openedAt' => new DateTimeImmutable('2024-01-15 10:00:00'),
            'closedAt' => new DateTimeImmutable('2024-01-10 10:00:00'),
            'sepaSnapshotId' => 100,
        ]);

        $result = $evaluator->evaluateTradeSample($sample);

        $this->assertEquals(EvidenceEligibilityStatus::excluded(), $result->status);
        $this->assertEquals(EvidenceExclusionReason::invalidTimeOrder(), $result->exclusionReason);
    }

    private function createSample(array $overrides = []): EvidenceTradeSample
    {
        $data = array_merge([
            'campaignId' => 1,
            'instrumentId' => 1,
            'tradeType' => 'live',
            'campaignState' => 'closed_profit',
            'openedAt' => new DateTimeImmutable('2024-01-01 10:00:00'),
            'closedAt' => new DateTimeImmutable('2024-01-10 10:00:00'),
            'holdingDays' => 9,
            'totalQuantity' => '100',
            'openQuantity' => null,
            'avgEntryPrice' => '150.00',
            'realizedPnlGross' => '150.00',
            'realizedPnlNet' => '140.00',
            'realizedPnlPct' => '0.10',
            'entryEventId' => 1,
            'exitEventId' => 2,
            'exitReason' => 'signal',
            'buySignalSnapshotId' => null,
            'sepaSnapshotId' => null,
            'epaSnapshotId' => null,
            'scoringVersion' => null,
            'policyVersion' => null,
            'modelVersion' => null,
            'macroVersion' => null,
            'seedSource' => 'live',
            'eligibilityStatus' => null,
            'exclusionReason' => null,
            'dataQualityFlags' => [],
        ], $overrides);

        return new EvidenceTradeSample(
            campaignId: $data['campaignId'],
            instrumentId: $data['instrumentId'],
            tradeType: $data['tradeType'],
            campaignState: $data['campaignState'],
            openedAt: $data['openedAt'],
            closedAt: $data['closedAt'],
            holdingDays: $data['holdingDays'],
            totalQuantity: $data['totalQuantity'],
            openQuantity: $data['openQuantity'],
            avgEntryPrice: $data['avgEntryPrice'],
            realizedPnlGross: $data['realizedPnlGross'],
            realizedPnlNet: $data['realizedPnlNet'],
            realizedPnlPct: $data['realizedPnlPct'],
            entryEventId: $data['entryEventId'],
            exitEventId: $data['exitEventId'],
            exitReason: $data['exitReason'],
            buySignalSnapshotId: $data['buySignalSnapshotId'],
            sepaSnapshotId: $data['sepaSnapshotId'],
            epaSnapshotId: $data['epaSnapshotId'],
            scoringVersion: $data['scoringVersion'],
            policyVersion: $data['policyVersion'],
            modelVersion: $data['modelVersion'],
            macroVersion: $data['macroVersion'],
            seedSource: $data['seedSource'],
            eligibilityStatus: $data['eligibilityStatus'],
            exclusionReason: $data['exclusionReason'],
            dataQualityFlags: $data['dataQualityFlags'],
        );
    }

    /**
     * @param callable(Connection): void $seed
     */
    private function createValidatedEvaluator(callable $seed): EvidenceEligibilityEvaluator
    {
        $connection = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'memory' => true,
        ]);

        $this->createValidationSchema($connection);
        $seed($connection);

        return new EvidenceEligibilityEvaluator(new SnapshotValidationService($connection));
    }

    private function createValidationSchema(Connection $connection): void
    {
        $connection->executeStatement(<<<'SQL'
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

        $connection->executeStatement(<<<'SQL'
            CREATE TABLE instrument_buy_signal_snapshot (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                instrument_id INTEGER NOT NULL,
                as_of_date DATE NOT NULL,
                source_run_id INTEGER DEFAULT NULL,
                available_at DATETIME DEFAULT NULL
            )
            SQL);

        $connection->executeStatement(<<<'SQL'
            CREATE TABLE instrument_sepa_snapshot (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                instrument_id INTEGER NOT NULL,
                as_of_date DATE NOT NULL,
                source_run_id INTEGER DEFAULT NULL,
                available_at DATETIME DEFAULT NULL
            )
            SQL);

        $connection->executeStatement(<<<'SQL'
            CREATE TABLE instrument_epa_snapshot (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                instrument_id INTEGER NOT NULL,
                as_of_date DATE NOT NULL,
                source_run_id INTEGER DEFAULT NULL,
                available_at DATETIME DEFAULT NULL
            )
            SQL);
    }

    private function insertPipelineRun(Connection $connection, int $id, string $status, int $exitCode, string $finishedAt): void
    {
        $connection->executeStatement(
            'INSERT INTO pipeline_run (id, run_id, run_key, status, run_scope, run_path, finished_at, exit_code) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [$id, "run_$id", "run_$id", $status, 'portfolio', '/test', $finishedAt, $exitCode]
        );
    }

    private function insertBuySignalSnapshot(Connection $connection, int $id, int $instrumentId, ?int $sourceRunId, ?string $availableAt): void
    {
        $connection->executeStatement(
            'INSERT INTO instrument_buy_signal_snapshot (id, instrument_id, as_of_date, source_run_id, available_at) VALUES (?, ?, ?, ?, ?)',
            [$id, $instrumentId, '2024-01-10', $sourceRunId, $availableAt]
        );
    }

    private function insertSepaSnapshot(Connection $connection, int $id, int $instrumentId, ?int $sourceRunId, ?string $availableAt): void
    {
        $connection->executeStatement(
            'INSERT INTO instrument_sepa_snapshot (id, instrument_id, as_of_date, source_run_id, available_at) VALUES (?, ?, ?, ?, ?)',
            [$id, $instrumentId, '2024-01-10', $sourceRunId, $availableAt]
        );
    }

    private function insertEpaSnapshot(Connection $connection, int $id, int $instrumentId, ?int $sourceRunId, ?string $availableAt): void
    {
        $connection->executeStatement(
            'INSERT INTO instrument_epa_snapshot (id, instrument_id, as_of_date, source_run_id, available_at) VALUES (?, ?, ?, ?, ?)',
            [$id, $instrumentId, '2024-01-10', $sourceRunId, $availableAt]
        );
    }
}
