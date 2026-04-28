<?php

declare(strict_types=1);

namespace App\Tests\Service\Evidence\Fixture;

use App\Service\Evidence\Model\EvidenceTradeSample;
use DateTimeImmutable;

/**
 * Fixture builder for EvidenceTradeSample test data.
 *
 * Provides stable, neutral, deterministic test samples for Evidence Engine validation.
 * Uses synthetic identifiers only — no real stock tickers, company names, or ISINs.
 *
 * All samples use TEST_EQUITY_* placeholders for instrument references.
 * PnL values use ratio semantics: 0.15 = +15%, -0.08 = -8%.
 */
final class EvidenceTradeSampleFixture
{
    private const DEFAULT_CAMPAIGN_ID = 1000;
    private const DEFAULT_INSTRUMENT_ID = 100;
    private const DEFAULT_QUANTITY = '100.0000';
    private const DEFAULT_AVG_ENTRY_PRICE = '50.0000';

    /**
     * Create a closed profit sample.
     * Terminal state with positive PnL.
     *
     * @param array<string, mixed> $overrides Field overrides
     */
    public static function closedProfit(array $overrides = []): EvidenceTradeSample
    {
        return self::createSample([
            'campaignState' => 'closed_profit',
            'realizedPnlPct' => '0.15',
            'realizedPnlGross' => '750.00',
            'realizedPnlNet' => '745.50',
            ...$overrides,
        ]);
    }

    /**
     * Create a closed loss sample.
     * Terminal state with negative PnL.
     *
     * @param array<string, mixed> $overrides Field overrides
     */
    public static function closedLoss(array $overrides = []): EvidenceTradeSample
    {
        return self::createSample([
            'campaignState' => 'closed_loss',
            'realizedPnlPct' => '-0.08',
            'realizedPnlGross' => '-400.00',
            'realizedPnlNet' => '-404.00',
            ...$overrides,
        ]);
    }

    /**
     * Create a closed neutral sample.
     * Terminal state with exactly zero PnL.
     *
     * @param array<string, mixed> $overrides Field overrides
     */
    public static function closedNeutral(array $overrides = []): EvidenceTradeSample
    {
        return self::createSample([
            'campaignState' => 'closed_neutral',
            'realizedPnlPct' => '0.00',
            'realizedPnlGross' => '0.00',
            'realizedPnlNet' => '0.00',
            ...$overrides,
        ]);
    }

    /**
     * Create a returned-to-watchlist sample.
     * Terminal state without position execution.
     *
     * @param array<string, mixed> $overrides Field overrides
     */
    public static function returnedToWatchlist(array $overrides = []): EvidenceTradeSample
    {
        return self::createSample([
            'campaignState' => 'returned_to_watchlist',
            'realizedPnlPct' => '0.00',
            'realizedPnlGross' => '0.00',
            'realizedPnlNet' => '0.00',
            ...$overrides,
        ]);
    }

    /**
     * Create an open campaign sample.
     * Non-terminal state — should be excluded from aggregation.
     *
     * @param array<string, mixed> $overrides Field overrides
     */
    public static function openCampaign(array $overrides = []): EvidenceTradeSample
    {
        return self::createSample([
            'campaignState' => 'open',
            'closedAt' => null,
            'holdingDays' => null,
            'realizedPnlPct' => null,
            'realizedPnlGross' => null,
            'realizedPnlNet' => null,
            ...$overrides,
        ]);
    }

    /**
     * Create a trimmed campaign sample.
     * Non-terminal partial exit state.
     *
     * @param array<string, mixed> $overrides Field overrides
     */
    public static function trimmedCampaign(array $overrides = []): EvidenceTradeSample
    {
        return self::createSample([
            'campaignState' => 'trimmed',
            'closedAt' => null,
            'holdingDays' => null,
            'realizedPnlPct' => null,
            ...$overrides,
        ]);
    }

    /**
     * Create a migration seed sample.
     * Indicates legacy data origin.
     *
     * @param array<string, mixed> $overrides Field overrides
     */
    public static function migrationSeed(array $overrides = []): EvidenceTradeSample
    {
        return self::createSample([
            'seedSource' => 'migration',
            'buySignalSnapshotId' => null,
            'sepaSnapshotId' => null,
            'epaSnapshotId' => null,
            ...$overrides,
        ]);
    }

    /**
     * Create a manual seed sample.
     * Indicates manually entered data origin.
     *
     * @param array<string, mixed> $overrides Field overrides
     */
    public static function manualSeed(array $overrides = []): EvidenceTradeSample
    {
        return self::createSample([
            'seedSource' => 'manual',
            'buySignalSnapshotId' => null,
            'sepaSnapshotId' => null,
            'epaSnapshotId' => null,
            ...$overrides,
        ]);
    }

    /**
     * Create a sample with missing snapshots.
     * No entry context available — eligible_outcome_only.
     *
     * @param array<string, mixed> $overrides Field overrides
     */
    public static function missingSnapshots(array $overrides = []): EvidenceTradeSample
    {
        return self::createSample([
            'buySignalSnapshotId' => null,
            'sepaSnapshotId' => null,
            'epaSnapshotId' => null,
            ...$overrides,
        ]);
    }

    /**
     * Create a sample with snapshot IDs present.
     * IDs are intentionally unvalidated; C3 should keep this outcome_only
     * until DB-level snapshot validation exists.
     *
     * @param array<string, mixed> $overrides Field overrides
     */
    public static function withSnapshotIds(array $overrides = []): EvidenceTradeSample
    {
        return self::createSample([
            'buySignalSnapshotId' => 2001,
            'sepaSnapshotId' => 3001,
            'epaSnapshotId' => 4001,
            ...$overrides,
        ]);
    }

    /**
     * Create a sample with invalid time order.
     * Closed before opened — should be excluded.
     *
     * @param array<string, mixed> $overrides Field overrides
     */
    public static function invalidTimeOrder(array $overrides = []): EvidenceTradeSample
    {
        $openedAt = new DateTimeImmutable('2024-01-15 10:00:00');
        $closedAt = new DateTimeImmutable('2024-01-10 14:30:00'); // Before opened

        return self::createSample([
            'openedAt' => $openedAt,
            'closedAt' => $closedAt,
            'holdingDays' => -5, // Negative holding period
            ...$overrides,
        ]);
    }

    /**
     * Create a sample with missing PnL fields.
     * Terminal state but no outcome data — should be excluded.
     *
     * @param array<string, mixed> $overrides Field overrides
     */
    public static function missingPnl(array $overrides = []): EvidenceTradeSample
    {
        return self::createSample([
            'realizedPnlPct' => null,
            'realizedPnlGross' => null,
            'realizedPnlNet' => null,
            ...$overrides,
        ]);
    }

    /**
     * Create a sample with missing closed_at timestamp.
     * Terminal state but no close date — should be excluded.
     *
     * @param array<string, mixed> $overrides Field overrides
     */
    public static function missingClosedAt(array $overrides = []): EvidenceTradeSample
    {
        return self::createSample([
            'closedAt' => null,
            'holdingDays' => null,
            ...$overrides,
        ]);
    }

    /**
     * Create a sample with unknown state.
     * Unrecognized campaign state — should be excluded.
     *
     * @param array<string, mixed> $overrides Field overrides
     */
    public static function unknownState(array $overrides = []): EvidenceTradeSample
    {
        return self::createSample([
            'campaignState' => 'unknown_custom_state',
            ...$overrides,
        ]);
    }

    // =================================================================
    // Base Factory
    // =================================================================

    /**
     * Create a base EvidenceTradeSample with sensible defaults.
     *
     * @param array<string, mixed> $overrides Field overrides
     */
    private static function createSample(array $overrides = []): EvidenceTradeSample
    {
        $campaignId = $overrides['campaignId'] ?? self::DEFAULT_CAMPAIGN_ID;
        $instrumentId = $overrides['instrumentId'] ?? self::DEFAULT_INSTRUMENT_ID;
        $openedAt = $overrides['openedAt'] ?? new DateTimeImmutable('2024-01-15 10:00:00');
        $closedAt = $overrides['closedAt'] ?? new DateTimeImmutable('2024-03-15 14:30:00');
        $holdingDays = $overrides['holdingDays'] ?? 60;

        return new EvidenceTradeSample(
            campaignId: $campaignId,
            instrumentId: $instrumentId,
            tradeType: $overrides['tradeType'] ?? 'live',
            campaignState: $overrides['campaignState'] ?? 'closed_profit',
            openedAt: $openedAt,
            closedAt: $closedAt,
            holdingDays: $holdingDays,
            totalQuantity: $overrides['totalQuantity'] ?? self::DEFAULT_QUANTITY,
            openQuantity: $overrides['openQuantity'] ?? '0.0000',
            avgEntryPrice: $overrides['avgEntryPrice'] ?? self::DEFAULT_AVG_ENTRY_PRICE,
            realizedPnlGross: array_key_exists('realizedPnlGross', $overrides) ? $overrides['realizedPnlGross'] : '500.00',
            realizedPnlNet: array_key_exists('realizedPnlNet', $overrides) ? $overrides['realizedPnlNet'] : '495.00',
            realizedPnlPct: array_key_exists('realizedPnlPct', $overrides) ? $overrides['realizedPnlPct'] : '0.10',
            entryEventId: $overrides['entryEventId'] ?? 1001,
            exitEventId: $overrides['exitEventId'] ?? 1002,
            exitReason: $overrides['exitReason'] ?? 'signal',
            buySignalSnapshotId: $overrides['buySignalSnapshotId'] ?? null,
            sepaSnapshotId: $overrides['sepaSnapshotId'] ?? null,
            epaSnapshotId: $overrides['epaSnapshotId'] ?? null,
            scoringVersion: $overrides['scoringVersion'] ?? 'v1.0',
            policyVersion: $overrides['policyVersion'] ?? 'v1.0',
            modelVersion: $overrides['modelVersion'] ?? null,
            macroVersion: $overrides['macroVersion'] ?? null,
            seedSource: $overrides['seedSource'] ?? 'live',
            eligibilityStatus: $overrides['eligibilityStatus'] ?? null,
            exclusionReason: $overrides['exclusionReason'] ?? null,
            dataQualityFlags: $overrides['dataQualityFlags'] ?? [],
        );
    }
}