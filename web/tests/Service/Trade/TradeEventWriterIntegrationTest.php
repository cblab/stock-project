<?php

declare(strict_types=1);

namespace App\Tests\Service\Trade;

use App\Service\Trade\TradeEventWriter;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Integration tests for TradeEventWriter verifying BUG-01 fix.
 *
 * Tests Campaign-Level realized_pnl_pct calculation for Trim + Exit flows.
 * Ensures cumulative P&L is used, not last exit percentage.
 */
final class TradeEventWriterIntegrationTest extends KernelTestCase
{
    private TradeEventWriter $writer;
    private \Doctrine\DBAL\Connection $connection;
    private static int $testRunId = 0;
    private int $testInstrumentId;

    protected function setUp(): void
    {
        self::bootKernel(['environment' => 'test']);
        $container = self::getContainer();
        $this->writer = $container->get(TradeEventWriter::class);
        $this->connection = $container->get('doctrine.dbal.default_connection');

        // Unique instrument ID per test to ensure isolation
        self::$testRunId++;
        $this->testInstrumentId = 990000 + self::$testRunId;

        $this->connection->beginTransaction();
        $this->seedTestData();
    }

    protected function tearDown(): void
    {
        // Rollback transaction to ensure test isolation
        if ($this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }
        parent::tearDown();
    }

    private function seedTestData(): void
    {
        $instrumentId = $this->testInstrumentId;
        $testDate = '2025-01-15';

        // Insert test instrument with all NOT NULL columns
        $this->connection->insert('instrument', [
            'id' => $instrumentId,
            'input_ticker' => 'TEST' . $instrumentId,
            'provider_ticker' => 'TEST' . $instrumentId,
            'display_ticker' => 'TEST' . $instrumentId,
            'name' => 'Test Instrument ' . $instrumentId,
            'isin' => 'TEST' . str_pad((string)$instrumentId, 6, '0', STR_PAD_LEFT),
            'asset_class' => 'Equity',
            'active' => 1,
            'is_portfolio' => 1,
            'region_exposure' => '[]',
            'sector_profile' => '[]',
            'top_holdings_profile' => '[]',
            'macro_profile' => '[]',
            'created_at' => $testDate . ' 00:00:00',
            'updated_at' => $testDate . ' 00:00:00',
        ]);

        // Insert valid buy_signal_snapshot with all required fields
        $this->connection->insert('instrument_buy_signal_snapshot', [
            'instrument_id' => $instrumentId,
            'as_of_date' => $testDate,
            'kronos_score' => 0.5,
            'sentiment_score' => 0.5,
            'merged_score' => 0.5,
            'decision' => 'buy',
            'created_at' => $testDate . ' 00:00:00',
            'updated_at' => $testDate . ' 00:00:00',
        ]);

        // Insert valid sepa_snapshot with all NOT NULL columns
        $this->connection->insert('instrument_sepa_snapshot', [
            'instrument_id' => $instrumentId,
            'as_of_date' => $testDate,
            'market_score' => 50.0,
            'stage_score' => 50.0,
            'relative_strength_score' => 50.0,
            'base_quality_score' => 50.0,
            'volume_score' => 50.0,
            'momentum_score' => 50.0,
            'risk_score' => 50.0,
            'superperformance_score' => 50.0,
            'total_score' => 50.0,
            'vcp_score' => 50.0,
            'microstructure_score' => 50.0,
            'breakout_readiness_score' => 50.0,
            'structure_score' => 50.0,
            'execution_score' => 50.0,
            'traffic_light' => 'green',
            'kill_triggers_json' => '{}',
            'detail_json' => '{}',
            'created_at' => $testDate . ' 00:00:00',
            'updated_at' => $testDate . ' 00:00:00',
        ]);

        // Insert valid epa_snapshot with all NOT NULL columns
        $this->connection->insert('instrument_epa_snapshot', [
            'instrument_id' => $instrumentId,
            'as_of_date' => $testDate,
            'failure_score' => 50.0,
            'trend_exit_score' => 50.0,
            'climax_score' => 50.0,
            'risk_score' => 50.0,
            'total_score' => 50.0,
            'action' => 'hold',
            'hard_triggers_json' => '{}',
            'soft_warnings_json' => '{}',
            'detail_json' => '{}',
            'created_at' => $testDate . ' 00:00:00',
            'updated_at' => $testDate . ' 00:00:00',
        ]);
    }

    /**
     * BUG-01 Fix Test Case 1: Trim + Exit with neutral result.
     *
     * Scenario:
     * - Entry: 100 shares @ 10€ (total cost: 1000€)
     * - Trim: Sell 50 shares @ 12€ (+100€ gross, +20% on this portion)
     * - Hard Exit: Sell 50 shares @ 8€ (-100€ gross, -20% on this portion)
     * - Expected Campaign P&L: 0€ (0%)
     *
     * Before BUG-01 fix, realized_pnl_pct might incorrectly show -20%
     * (from last exit) instead of 0% (campaign-level).
     */
    public function testTrimThenExitCalculatesCorrectNeutralCampaignPnl(): void
    {
        $instrumentId = $this->testInstrumentId;
        $baseTime = new \DateTimeImmutable('2025-01-15 10:00:00');

        // Step 1: Entry
        $this->writer->write([
            'instrument_id' => $instrumentId,
            'event_type' => 'entry',
            'event_timestamp' => $baseTime->format('Y-m-d H:i:s'),
            'event_price' => '10.00',
            'quantity' => '100',
        ]);

        // Step 2: Trim 50 @ 12€
        $this->writer->write([
            'instrument_id' => $instrumentId,
            'event_type' => 'trim',
            'event_timestamp' => $baseTime->modify('+1 hour')->format('Y-m-d H:i:s'),
            'event_price' => '12.00',
            'quantity' => '50',
            'exit_reason' => 'rebalance',
        ]);

        // Step 3: Hard Exit 50 @ 8€
        $result = $this->writer->write([
            'instrument_id' => $instrumentId,
            'event_type' => 'hard_exit',
            'event_timestamp' => $baseTime->modify('+2 hours')->format('Y-m-d H:i:s'),
            'event_price' => '8.00',
            'quantity' => '50',
            'exit_reason' => 'stop_loss',
        ]);

        // Verify: Campaign should be closed_neutral (0% P&L)
        self::assertSame('closed_neutral', $result->campaignState);

        $campaign = $this->connection->fetchAssociative(
            'SELECT * FROM trade_campaign WHERE instrument_id = ?',
            [$instrumentId]
        );

        // BUG-01 FIX VERIFICATION:
        // Campaign realized_pnl_gross should be 0 (100 - 100)
        self::assertEqualsWithDelta(0.0, (float) $campaign['realized_pnl_gross'], 0.01);

        // Campaign realized_pnl_pct should be 0% (0 / (10 * 100))
        // NOT -20% (which would be -100 / (10 * 50) from last exit)
        self::assertEqualsWithDelta(0.0, (float) $campaign['realized_pnl_pct'], 0.0001);

        // Verify open_quantity is 0 (fully closed)
        self::assertEqualsWithDelta(0.0, (float) $campaign['open_quantity'], 0.0001);
    }

    /**
     * BUG-01 Fix Test Case 2: Profitable Trim + Exit.
     *
     * Scenario:
     * - Entry: 100 shares @ 10€ (total cost: 1000€)
     * - Trim: Sell 50 shares @ 12€ (+100€ gross)
     * - Hard Exit: Sell 50 shares @ 14€ (+200€ gross)
     * - Expected Campaign P&L: +300€ (+30%)
     */
    public function testTrimThenExitCalculatesCorrectProfitCampaignPnl(): void
    {
        $instrumentId = $this->testInstrumentId;
        $baseTime = new \DateTimeImmutable('2025-01-15 10:00:00');

        // Entry
        $this->writer->write([
            'instrument_id' => $instrumentId,
            'event_type' => 'entry',
            'event_timestamp' => $baseTime->format('Y-m-d H:i:s'),
            'event_price' => '10.00',
            'quantity' => '100',
        ]);

        // Trim 50 @ 12€
        $this->writer->write([
            'instrument_id' => $instrumentId,
            'event_type' => 'trim',
            'event_timestamp' => $baseTime->modify('+1 hour')->format('Y-m-d H:i:s'),
            'event_price' => '12.00',
            'quantity' => '50',
            'exit_reason' => 'rebalance',
        ]);

        // Hard Exit 50 @ 14€
        $result = $this->writer->write([
            'instrument_id' => $instrumentId,
            'event_type' => 'hard_exit',
            'event_timestamp' => $baseTime->modify('+2 hours')->format('Y-m-d H:i:s'),
            'event_price' => '14.00',
            'quantity' => '50',
            'exit_reason' => 'signal',
        ]);

        // Verify: Campaign should be closed_profit
        self::assertSame('closed_profit', $result->campaignState);

        $campaign = $this->connection->fetchAssociative(
            'SELECT * FROM trade_campaign WHERE instrument_id = ?',
            [$instrumentId]
        );

        // realized_pnl_gross = (12-10)*50 + (14-10)*50 = 100 + 200 = 300
        self::assertEqualsWithDelta(300.0, (float) $campaign['realized_pnl_gross'], 0.01);

        // realized_pnl_pct = 300 / (10 * 100) = 0.30 = 30%
        self::assertEqualsWithDelta(0.30, (float) $campaign['realized_pnl_pct'], 0.0001);
    }

    /**
     * BUG-01 Fix Test Case 3: Partial loss Trim + profitable Exit.
     *
     * Scenario:
     * - Entry: 100 shares @ 10€ (total cost: 1000€)
     * - Trim: Sell 25 shares @ 8€ (-50€ gross on this portion)
     * - Hard Exit: Sell 75 shares @ 12€ (+150€ gross on this portion)
     * - Expected Campaign P&L: +100€ (+10%)
     */
    public function testPartialLossTrimThenProfitExitCalculatesCorrectCampaignPnl(): void
    {
        $instrumentId = $this->testInstrumentId;
        $baseTime = new \DateTimeImmutable('2025-01-15 10:00:00');

        // Entry
        $this->writer->write([
            'instrument_id' => $instrumentId,
            'event_type' => 'entry',
            'event_timestamp' => $baseTime->format('Y-m-d H:i:s'),
            'event_price' => '10.00',
            'quantity' => '100',
        ]);

        // Trim 25 @ 8€ (loss)
        $this->writer->write([
            'instrument_id' => $instrumentId,
            'event_type' => 'trim',
            'event_timestamp' => $baseTime->modify('+1 hour')->format('Y-m-d H:i:s'),
            'event_price' => '8.00',
            'quantity' => '25',
            'exit_reason' => 'rebalance',
        ]);

        // Hard Exit 75 @ 12€ (profit)
        $result = $this->writer->write([
            'instrument_id' => $instrumentId,
            'event_type' => 'hard_exit',
            'event_timestamp' => $baseTime->modify('+2 hours')->format('Y-m-d H:i:s'),
            'event_price' => '12.00',
            'quantity' => '75',
            'exit_reason' => 'signal',
        ]);

        // Verify: Campaign should be closed_profit
        self::assertSame('closed_profit', $result->campaignState);

        $campaign = $this->connection->fetchAssociative(
            'SELECT * FROM trade_campaign WHERE instrument_id = ?',
            [$instrumentId]
        );

        // realized_pnl_gross = (8-10)*25 + (12-10)*75 = -50 + 150 = 100
        self::assertEqualsWithDelta(100.0, (float) $campaign['realized_pnl_gross'], 0.01);

        // realized_pnl_pct = 100 / (10 * 100) = 0.10 = 10%
        self::assertEqualsWithDelta(0.10, (float) $campaign['realized_pnl_pct'], 0.0001);
    }

    /**
     * BUG-01 Fix Test Case 4: return_to_watchlist behaves identically to hard_exit for PnL calculation.
     */
    public function testReturnToWatchlistCalculatesCorrectCampaignPnl(): void
    {
        $instrumentId = $this->testInstrumentId;
        $baseTime = new \DateTimeImmutable('2025-01-15 10:00:00');

        // Entry
        $this->writer->write([
            'instrument_id' => $instrumentId,
            'event_type' => 'entry',
            'event_timestamp' => $baseTime->format('Y-m-d H:i:s'),
            'event_price' => '10.00',
            'quantity' => '100',
        ]);

        // Trim 50 @ 12€
        $this->writer->write([
            'instrument_id' => $instrumentId,
            'event_type' => 'trim',
            'event_timestamp' => $baseTime->modify('+1 hour')->format('Y-m-d H:i:s'),
            'event_price' => '12.00',
            'quantity' => '50',
            'exit_reason' => 'rebalance',
        ]);

        // Return to watchlist 50 @ 8€
        $result = $this->writer->write([
            'instrument_id' => $instrumentId,
            'event_type' => 'return_to_watchlist',
            'event_timestamp' => $baseTime->modify('+2 hours')->format('Y-m-d H:i:s'),
            'event_price' => '8.00',
            'quantity' => '50',
            'exit_reason' => 'manual',
        ]);

        // Verify: Campaign should be returned_to_watchlist
        self::assertSame('returned_to_watchlist', $result->campaignState);

        $campaign = $this->connection->fetchAssociative(
            'SELECT * FROM trade_campaign WHERE instrument_id = ?',
            [$instrumentId]
        );

        // realized_pnl_gross = (12-10)*50 + (8-10)*50 = 100 - 100 = 0
        self::assertEqualsWithDelta(0.0, (float) $campaign['realized_pnl_gross'], 0.01);

        // realized_pnl_pct should be 0% (campaign-level, not last exit)
        self::assertEqualsWithDelta(0.0, (float) $campaign['realized_pnl_pct'], 0.0001);
    }

    /**
     * BUG-01 Fix Test Case 5: Multiple trims before exit.
     *
     * Scenario:
     * - Entry: 100 shares @ 10€
     * - Trim 1: Sell 25 shares @ 12€ (+50€)
     * - Trim 2: Sell 25 shares @ 8€ (-50€)
     * - Hard Exit: Sell 50 shares @ 10€ (0€)
     * - Expected Campaign P&L: 0€ (0%)
     */
    public function testMultipleTrimsThenExitCalculatesCorrectCampaignPnl(): void
    {
        $instrumentId = $this->testInstrumentId;
        $baseTime = new \DateTimeImmutable('2025-01-15 10:00:00');

        // Entry
        $this->writer->write([
            'instrument_id' => $instrumentId,
            'event_type' => 'entry',
            'event_timestamp' => $baseTime->format('Y-m-d H:i:s'),
            'event_price' => '10.00',
            'quantity' => '100',
        ]);

        // Trim 1: 25 @ 12€ (+50€)
        $this->writer->write([
            'instrument_id' => $instrumentId,
            'event_type' => 'trim',
            'event_timestamp' => $baseTime->modify('+1 hour')->format('Y-m-d H:i:s'),
            'event_price' => '12.00',
            'quantity' => '25',
            'exit_reason' => 'rebalance',
        ]);

        // Trim 2: 25 @ 8€ (-50€)
        $this->writer->write([
            'instrument_id' => $instrumentId,
            'event_type' => 'trim',
            'event_timestamp' => $baseTime->modify('+2 hours')->format('Y-m-d H:i:s'),
            'event_price' => '8.00',
            'quantity' => '25',
            'exit_reason' => 'rebalance',
        ]);

        // Hard Exit: 50 @ 10€ (0€)
        $result = $this->writer->write([
            'instrument_id' => $instrumentId,
            'event_type' => 'hard_exit',
            'event_timestamp' => $baseTime->modify('+3 hours')->format('Y-m-d H:i:s'),
            'event_price' => '10.00',
            'quantity' => '50',
            'exit_reason' => 'time_based',
        ]);

        self::assertSame('closed_neutral', $result->campaignState);

        $campaign = $this->connection->fetchAssociative(
            'SELECT * FROM trade_campaign WHERE instrument_id = ?',
            [$instrumentId]
        );

        // realized_pnl_gross = 50 - 50 + 0 = 0
        self::assertEqualsWithDelta(0.0, (float) $campaign['realized_pnl_gross'], 0.01);

        // realized_pnl_pct = 0 / (10 * 100) = 0%
        self::assertEqualsWithDelta(0.0, (float) $campaign['realized_pnl_pct'], 0.0001);
    }
}