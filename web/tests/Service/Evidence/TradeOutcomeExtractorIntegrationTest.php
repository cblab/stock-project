<?php

declare(strict_types=1);

namespace App\Tests\Service\Evidence;

use App\Service\Evidence\Model\EvidenceDataQualityFlag;
use App\Service\Evidence\Model\EvidenceEligibilityStatus;
use App\Service\Evidence\Model\EvidenceExclusionReason;
use App\Service\Evidence\Model\EvidenceTradeSample;
use App\Service\Evidence\TradeOutcomeExtractor;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Integration tests for TradeOutcomeExtractor.
 *
 * Tests the v0.4 Truth Layer → C2 Evidence mapping with eligibility rules.
 * Each test creates its own fixture data and verifies extraction behavior.
 */
final class TradeOutcomeExtractorIntegrationTest extends KernelTestCase
{
    private Connection $connection;
    private TradeOutcomeExtractor $extractor;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->connection = self::getContainer()->get(Connection::class);
        $this->extractor = new TradeOutcomeExtractor($this->connection);

        // Clean slate for each test
        $this->cleanFixtures();
    }

    protected function tearDown(): void
    {
        $this->cleanFixtures();
        parent::tearDown();
    }

    private function cleanFixtures(): void
    {
        // Cleanup nur für Test-Daten mit Prefix TST_C2_
        $this->connection->executeStatement('
            DELETE tml FROM trade_migration_log tml
            JOIN trade_campaign tc ON tml.trade_campaign_id = tc.id
            JOIN instrument i ON tc.instrument_id = i.id
            WHERE i.input_ticker LIKE \'TST_C2_%\'
        ');
        $this->connection->executeStatement('
            DELETE te FROM trade_event te
            JOIN trade_campaign tc ON te.trade_campaign_id = tc.id
            JOIN instrument i ON tc.instrument_id = i.id
            WHERE i.input_ticker LIKE \'TST_C2_%\'
        ');
        $this->connection->executeStatement('
            DELETE tc FROM trade_campaign tc
            JOIN instrument i ON tc.instrument_id = i.id
            WHERE i.input_ticker LIKE \'TST_C2_%\'
        ');
        $this->connection->executeStatement('DELETE FROM instrument WHERE input_ticker LIKE \'TST_C2_%\'');
    }

    // =================================================================
    // TEST 1: Non-terminal campaigns are excluded
    // =================================================================
    public function test_extractClosedSamples_excludes_non_terminal_campaigns(): void
    {
        // Given: non-terminal campaigns
        $instrumentId = $this->createInstrument('TST_C2_AAPL');
        $this->createCampaign([
            'instrument_id' => $instrumentId,
            'state' => 'open',
            'trade_type' => 'live',
            'total_quantity' => '100',
        ]);
        $this->createCampaign([
            'instrument_id' => $instrumentId,
            'state' => 'trimmed',
            'trade_type' => 'live',
            'total_quantity' => '50',
        ]);
        $this->createCampaign([
            'instrument_id' => $instrumentId,
            'state' => 'paused',
            'trade_type' => 'live',
            'total_quantity' => '75',
        ]);

        // When
        $samples = $this->extractor->extractClosedSamples();

        // Then
        $this->assertEmpty($samples);
    }

    // =================================================================
    // TEST 2: Terminal campaigns are returned
    // =================================================================
    public function test_extractClosedSamples_returns_terminal_campaigns(): void
    {
        // Given: terminal campaigns
        $instrumentId = $this->createInstrument('TST_C2_AAPL2');
        $campaign1 = $this->createCampaign([
            'instrument_id' => $instrumentId,
            'state' => 'closed_profit',
            'trade_type' => 'live',
            'total_quantity' => '100',
            'realized_pnl_gross' => '500.00',
            'realized_pnl_net' => '475.00',
            'realized_pnl_pct' => '0.05',
            'opened_at' => '2024-01-01 10:00:00',
            'closed_at' => '2024-01-15 10:00:00',
        ]);
        $campaign2 = $this->createCampaign([
            'instrument_id' => $instrumentId,
            'state' => 'returned_to_watchlist',
            'trade_type' => 'live',
            'total_quantity' => '0',
            'realized_pnl_gross' => '0.00',
            'realized_pnl_net' => '0.00',
            'realized_pnl_pct' => '0.00',
            'opened_at' => '2024-02-01 10:00:00',
            'closed_at' => '2024-02-10 10:00:00',
        ]);

        $this->createEntryEvent($campaign1, 'entry', [
            'event_timestamp' => '2024-01-01 10:00:00',
        ]);
        $this->createExitEvent($campaign1, 'hard_exit', [
            'event_timestamp' => '2024-01-15 10:00:00',
            'exit_reason' => 'signal',
        ]);
        $this->createEntryEvent($campaign2, 'entry', [
            'event_timestamp' => '2024-02-01 10:00:00',
        ]);
        $this->createExitEvent($campaign2, 'return_to_watchlist', [
            'event_timestamp' => '2024-02-10 10:00:00',
        ]);

        // When
        $samples = $this->extractor->extractClosedSamples();

        // Then
        $this->assertCount(2, $samples);

        $profitSample = $this->findSampleByCampaignId($samples, $campaign1);
        $this->assertNotNull($profitSample);
        $this->assertSame('closed_profit', $profitSample->campaignState);
        $this->assertSame(0.05, (float) $profitSample->realizedPnlPct);
        $this->assertSame(14, $profitSample->holdingDays);
        $this->assertSame('signal', $profitSample->exitReason);

        $returnSample = $this->findSampleByCampaignId($samples, $campaign2);
        $this->assertNotNull($returnSample);
        $this->assertSame('returned_to_watchlist', $returnSample->campaignState);
        $this->assertSame(9, $returnSample->holdingDays);
    }

    // =================================================================
    // TEST 3: P&L and versions populated from entry event
    // =================================================================
    public function test_extractClosedSamples_populates_pnl_and_versions(): void
    {
        // Given
        $instrumentId = $this->createInstrument('TST_C2_MSFT');
        $campaign = $this->createCampaign([
            'instrument_id' => $instrumentId,
            'state' => 'closed_profit',
            'trade_type' => 'live',
            'total_quantity' => '200',
            'realized_pnl_gross' => '1200.50',
            'realized_pnl_net' => '1150.25',
            'realized_pnl_pct' => '0.12',
            'opened_at' => '2024-03-01 09:30:00',
            'closed_at' => '2024-03-20 16:00:00',
        ]);

        $this->createEntryEvent($campaign, 'entry', [
            'event_timestamp' => '2024-03-01 09:30:00',
            'scoring_version' => '1.2.3',
            'policy_version' => '2.0.0',
            'model_version' => 'v3',
            'macro_version' => '2024-Q1',
        ]);
        $this->createExitEvent($campaign, 'hard_exit', [
            'event_timestamp' => '2024-03-20 16:00:00',
            'exit_reason' => 'stop_loss',
        ]);

        // When
        $samples = $this->extractor->extractClosedSamples();

        // Then
        $this->assertCount(1, $samples);
        $sample = $samples[0];

        $this->assertSame(1200.50, (float) $sample->realizedPnlGross);
        $this->assertSame(1150.25, (float) $sample->realizedPnlNet);
        $this->assertSame(0.12, (float) $sample->realizedPnlPct);
        $this->assertNull($sample->buySignalSnapshotId);
        $this->assertNull($sample->sepaSnapshotId);
        $this->assertNull($sample->epaSnapshotId);
        $this->assertSame('1.2.3', $sample->scoringVersion);
        $this->assertSame('2.0.0', $sample->policyVersion);
        $this->assertSame('v3', $sample->modelVersion);
        $this->assertSame('2024-Q1', $sample->macroVersion);
        $this->assertSame('live', $sample->seedSource);
        $this->assertEquals(EvidenceEligibilityStatus::eligibleOutcomeOnly(), $sample->eligibilityStatus);
        $this->assertContainsEquals(EvidenceDataQualityFlag::snapshotIncomplete(), $sample->dataQualityFlags);
    }

    // =================================================================
    // TEST 4: Migration seed → outcome-only eligibility
    // =================================================================
    public function test_extractClosedSamples_marks_migration_seed_outcome_only(): void
    {
        // Given
        $instrumentId = $this->createInstrument('TST_C2_GOOGL');
        $campaign = $this->createCampaign([
            'instrument_id' => $instrumentId,
            'state' => 'closed_profit',
            'trade_type' => 'live',
            'total_quantity' => '50',
            'realized_pnl_pct' => '0.08',
            'opened_at' => '2024-01-01 10:00:00',
            'closed_at' => '2024-01-10 10:00:00',
        ]);

        $this->createEntryEvent($campaign, 'migration_seed', [
            'event_timestamp' => '2024-01-01 10:00:00',
        ]);
        $this->createMigrationLog($campaign, 'full');
        $this->createExitEvent($campaign, 'hard_exit', [
            'event_timestamp' => '2024-01-10 10:00:00',
        ]);

        // When
        $samples = $this->extractor->extractClosedSamples();

        // Then
        $this->assertCount(1, $samples);
        $sample = $samples[0];

        $this->assertSame('migration', $sample->seedSource);
        $this->assertEquals(EvidenceEligibilityStatus::eligibleOutcomeOnly(), $sample->eligibilityStatus);
        $this->assertNull($sample->exclusionReason);
        $this->assertContainsEquals(EvidenceDataQualityFlag::migrationSeed(), $sample->dataQualityFlags);
        $this->assertContainsEquals(EvidenceDataQualityFlag::containsSeedData(), $sample->dataQualityFlags);
    }

    // =================================================================
    // TEST 5: Manual seed → outcome-only eligibility
    // =================================================================
    public function test_extractClosedSamples_marks_manual_seed_outcome_only(): void
    {
        // Given
        $instrumentId = $this->createInstrument('TST_C2_TSLA');
        $campaign = $this->createCampaign([
            'instrument_id' => $instrumentId,
            'state' => 'closed_loss',
            'trade_type' => 'live',
            'total_quantity' => '100',
            'realized_pnl_pct' => '-0.15',
            'opened_at' => '2024-02-01 10:00:00',
            'closed_at' => '2024-02-05 10:00:00',
        ]);

        $this->createEntryEvent($campaign, 'migration_seed', [
            'event_timestamp' => '2024-02-01 10:00:00',
        ]);
        $this->createMigrationLog($campaign, 'manual_seed');
        $this->createExitEvent($campaign, 'hard_exit', [
            'event_timestamp' => '2024-02-05 10:00:00',
        ]);

        // When
        $samples = $this->extractor->extractClosedSamples();

        // Then
        $this->assertCount(1, $samples);
        $sample = $samples[0];

        $this->assertSame('manual', $sample->seedSource);
        $this->assertEquals(EvidenceEligibilityStatus::eligibleOutcomeOnly(), $sample->eligibilityStatus);
        $this->assertContainsEquals(EvidenceDataQualityFlag::manualSeed(), $sample->dataQualityFlags);
        $this->assertContainsEquals(EvidenceDataQualityFlag::containsSeedData(), $sample->dataQualityFlags);
    }

    // =================================================================
    // TEST 6: Open campaigns are excluded
    // =================================================================
    public function test_extractClosedSamples_excludes_open_campaign(): void
    {
        // Given
        $instrumentId = $this->createInstrument('TST_C2_NVDA');
        $this->createCampaign([
            'instrument_id' => $instrumentId,
            'state' => 'open',
            'trade_type' => 'live',
            'total_quantity' => '100',
            'opened_at' => '2024-01-01 10:00:00',
            'closed_at' => null,
            'realized_pnl_pct' => null,
        ]);

        // When
        $samples = $this->extractor->extractClosedSamples();

        // Then
        $this->assertEmpty($samples);
    }

    // =================================================================
    // TEST 7: Invalid time order → excluded
    // =================================================================
    public function test_extractClosedSamples_excludes_invalid_time_order(): void
    {
        // Given
        $instrumentId = $this->createInstrument('TST_C2_G');
        $campaign = $this->createCampaign([
            'instrument_id' => $instrumentId,
            'state' => 'closed_profit',
            'trade_type' => 'live',
            'total_quantity' => '100',
            'realized_pnl_pct' => '0.10',
            'opened_at' => '2024-03-20 10:00:00',
            'closed_at' => '2024-03-15 10:00:00', // Invalid: closed before opened
        ]);

        $this->createEntryEvent($campaign, 'entry', [
            'event_timestamp' => '2024-03-20 10:00:00',
        ]);
        $this->createExitEvent($campaign, 'hard_exit', [
            'event_timestamp' => '2024-03-15 10:00:00',
        ]);

        // When
        $samples = $this->extractor->extractClosedSamples();

        // Then
        $this->assertCount(1, $samples);
        $sample = $samples[0];

        $this->assertEquals(EvidenceEligibilityStatus::excluded(), $sample->eligibilityStatus);
        $this->assertEquals(EvidenceExclusionReason::invalidTimeOrder(), $sample->exclusionReason);
        $this->assertSame(5, $sample->holdingDays);
    }

    // =================================================================
    // TEST 8: Missing PnL → excluded
    // =================================================================
    public function test_extractClosedSamples_excludes_missing_pnl(): void
    {
        // Given
        $instrumentId = $this->createInstrument('TST_C2_H');
        $campaign = $this->createCampaign([
            'instrument_id' => $instrumentId,
            'state' => 'closed_loss',
            'trade_type' => 'live',
            'total_quantity' => '100',
            'realized_pnl_pct' => null, // Missing
            'realized_pnl_gross' => null,
            'realized_pnl_net' => null,
            'opened_at' => '2024-01-01 10:00:00',
            'closed_at' => '2024-01-10 10:00:00',
        ]);

        $this->createEntryEvent($campaign, 'entry', [
            'event_timestamp' => '2024-01-01 10:00:00',
        ]);
        $this->createExitEvent($campaign, 'hard_exit', [
            'event_timestamp' => '2024-01-10 10:00:00',
        ]);

        // When
        $samples = $this->extractor->extractClosedSamples();

        // Then
        $this->assertCount(1, $samples);
        $sample = $samples[0];

        $this->assertEquals(EvidenceEligibilityStatus::excluded(), $sample->eligibilityStatus);
        $this->assertEquals(EvidenceExclusionReason::missingPnl(), $sample->exclusionReason);
    }

    // =================================================================
    // TEST 9: Missing entry snapshots → outcome-only
    // =================================================================
    public function test_extractClosedSamples_missing_snapshots_outcome_only(): void
    {
        // Given
        $instrumentId = $this->createInstrument('TST_C2_I');
        $campaign = $this->createCampaign([
            'instrument_id' => $instrumentId,
            'state' => 'closed_profit',
            'trade_type' => 'live',
            'total_quantity' => '100',
            'realized_pnl_pct' => '0.25',
            'opened_at' => '2024-01-01 10:00:00',
            'closed_at' => '2024-01-20 10:00:00',
        ]);

        // Entry event with NO snapshots
        $this->createEntryEvent($campaign, 'entry', [
            'event_timestamp' => '2024-01-01 10:00:00',
            'buy_signal_snapshot_id' => null,
            'sepa_snapshot_id' => null,
            'epa_snapshot_id' => null,
        ]);
        $this->createExitEvent($campaign, 'hard_exit', [
            'event_timestamp' => '2024-01-20 10:00:00',
        ]);

        // When
        $samples = $this->extractor->extractClosedSamples();

        // Then
        $this->assertCount(1, $samples);
        $sample = $samples[0];

        $this->assertEquals(EvidenceEligibilityStatus::eligibleOutcomeOnly(), $sample->eligibilityStatus);
        $this->assertNull($sample->exclusionReason);
        $this->assertContainsEquals(EvidenceDataQualityFlag::missingEntrySnapshot(), $sample->dataQualityFlags);
        $this->assertContainsEquals(EvidenceDataQualityFlag::snapshotIncomplete(), $sample->dataQualityFlags);
    }

    // =================================================================
    // HELPER METHODS
    // =================================================================

    private function createInstrument(string $symbol): int
    {
        $now = date('Y-m-d H:i:s');
        $this->connection->insert('instrument', [
            'input_ticker' => $symbol,
            'provider_ticker' => $symbol,
            'display_ticker' => $symbol,
            'name' => $symbol . ' Test Instrument',
            'asset_class' => 'equity',
            'active' => 1,
            'is_portfolio' => 0,
            'region_exposure' => '[]',
            'sector_profile' => '[]',
            'top_holdings_profile' => '[]',
            'macro_profile' => '[]',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return (int) $this->connection->lastInsertId();
    }

    private function createCampaign(array $data): int
    {
        $defaults = [
            'instrument_id' => 1,
            'state' => 'open',
            'trade_type' => 'live',
            'total_quantity' => '0',
            'open_quantity' => '0',
            'avg_entry_price' => null,
            'realized_pnl_gross' => null,
            'realized_pnl_net' => null,
            'realized_pnl_pct' => null,
            'opened_at' => date('Y-m-d H:i:s'),
            'closed_at' => null,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ];

        $data = array_merge($defaults, $data);
        $this->connection->insert('trade_campaign', $data);

        return (int) $this->connection->lastInsertId();
    }

    private function createEntryEvent(int $campaignId, string $type, array $data): void
    {
        $instrumentId = $this->connection->fetchOne('SELECT instrument_id FROM trade_campaign WHERE id = ?', [$campaignId]);
        $defaults = [
            'trade_campaign_id' => $campaignId,
            'instrument_id' => $instrumentId,
            'event_type' => $type,
            'event_timestamp' => date('Y-m-d H:i:s'),
            'exit_reason' => null,
            'buy_signal_snapshot_id' => null,
            'sepa_snapshot_id' => null,
            'epa_snapshot_id' => null,
            'scoring_version' => null,
            'policy_version' => null,
            'model_version' => null,
            'macro_version' => null,
            'event_notes' => null,
            'created_at' => date('Y-m-d H:i:s'),
        ];

        $data = array_merge($defaults, $data);
        $this->connection->insert('trade_event', $data);
    }

    private function createExitEvent(int $campaignId, string $type, array $data): void
    {
        $instrumentId = $this->connection->fetchOne('SELECT instrument_id FROM trade_campaign WHERE id = ?', [$campaignId]);
        $defaults = [
            'trade_campaign_id' => $campaignId,
            'instrument_id' => $instrumentId,
            'event_type' => $type,
            'event_timestamp' => date('Y-m-d H:i:s'),
            'exit_reason' => null,
            'buy_signal_snapshot_id' => null,
            'sepa_snapshot_id' => null,
            'epa_snapshot_id' => null,
            'scoring_version' => null,
            'policy_version' => null,
            'model_version' => null,
            'macro_version' => null,
            'event_notes' => null,
            'created_at' => date('Y-m-d H:i:s'),
        ];

        $data = array_merge($defaults, $data);
        $this->connection->insert('trade_event', $data);
    }

    private function createMigrationLog(int $campaignId, string $status): void
    {
        $instrumentId = $this->connection->fetchOne('SELECT instrument_id FROM trade_campaign WHERE id = ?', [$campaignId]);
        $this->connection->insert('trade_migration_log', [
            'trade_campaign_id' => $campaignId,
            'instrument_id' => $instrumentId,
            'migration_status' => $status,
            'migrated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * @param EvidenceTradeSample[] $samples
     */
    private function findSampleByCampaignId(array $samples, int $campaignId): ?object
    {
        foreach ($samples as $sample) {
            if ($sample->campaignId === $campaignId) {
                return $sample;
            }
        }
        return null;
    }
}
