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

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->writer = $container->get(TradeEventWriter::class);
        $this->connection = $container->get('doctrine.dbal.default_connection');

        $this->seedTestData();
    }

    private function seedTestData(): void
    {
        // Ensure test instrument exists
        $exists = $this->connection->fetchOne(
            'SELECT 1 FROM instrument WHERE id = ?',
            [999999]
        );

        if (!$exists) {
            $this->connection->insert('instrument', [
                'id' => 999999,
                'symbol' => 'TEST',
                'name' => 'Test Instrument for TradeEventWriter',
                'isin' => 'TEST00000001',
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
        }

        // Ensure snapshots exist
        $snapshots = ['instrument_buy_signal_snapshot', 'instrument_sepa_snapshot', 'instrument_epa_snapshot'];
        foreach ($snapshots as $table) {
            $exists = $this->connection->fetchOne(
                "SELECT 1 FROM {$table} WHERE id = ?",
                [999999]
            );
            if (!$exists) {
                $this->connection->insert($table, [
                    'id' => 999999,
                    'instrument_id' => 999999,
                    'created_at' => date('Y-m-d H:i:s'),
                ]);
            }
        }

        // Clean up any existing test campaigns for this instrument
        $this->connection->executeStatement(
            'DELETE FROM trade_event WHERE trade_campaign_id IN (SELECT id FROM trade_campaign WHERE instrument_id = ?)',
            [999999]
        );
        $this->connection->executeStatement(
            'DELETE FROM trade_campaign WHERE instrument_id = ?',
            [999999]
        );
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
        $instrumentId = 999999;
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
            'SELECT * FROM trade_campaign WHERE id = ?',
            [$result->tradeCampaignId]
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
        $instrumentId = 999999;
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
            'SELECT * FROM trade_campaign WHERE id = ?',
            [$result->tradeCampaignId]
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
        $instrumentId = 999999;
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
            'SELECT * FROM trade_campaign WHERE id = ?',
            [$result->tradeCampaignId]
        );

        // realized_pnl_gross = (8-10)*25 + (12-10)*75 = -50 + 150 = 100
        self::assertEqualsWithDelta(100.0, (float) $campaign['realized_pnl_gross'], 0.01);

        // realized_pnl_pct = 100 / (10 * 100) = 0.10 = 10%
        self::assertEqualsWithDelta(0.10, (float) $campaign['realized_pnl_pct'], 0.0001);
    }

    /**
     * Test return_to_watchlist behaves identically to hard_exit for PnL calculation.
     */
    public function testReturnToWatchlistCalculatesCorrectCampaignPnl(): void
    {
        $instrumentId = 999999;
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
        ]);

        // Return to watchlist 50 @ 8€
        $result = $this->writer->write([
            'instrument_id' => $instrumentId,
            'event_type' => 'return_to_watchlist',
            'event_timestamp' => $baseTime->modify('+2 hours')->format('Y-m-d H:i:s'),
            'event_price' => '8.00',
            'quantity' => '50',
        ]);

        // Verify: Campaign should be returned_to_watchlist
        self::assertSame('returned_to_watchlist', $result->campaignState);

        $campaign = $this->connection->fetchAssociative(
            'SELECT * FROM trade_campaign WHERE id = ?',
            [$result->tradeCampaignId]
        );

        // realized_pnl_gross = (12-10)*50 + (8-10)*50 = 100 - 100 = 0
        self::assertEqualsWithDelta(0.0, (float) $campaign['realized_pnl_gross'], 0.01);

        // realized_pnl_pct should be 0% (campaign-level, not last exit)
        self::assertEqualsWithDelta(0.0, (float) $campaign['realized_pnl_pct'], 0.0001);
    }
}