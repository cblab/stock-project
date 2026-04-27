<?php

declare(strict_types=1);

namespace App\Service\Evidence\Model;

use DateTimeImmutable;

/**
 * Immutable DTO representing a single trade outcome evidence sample.
 *
 * Captures a completed trade campaign with all relevant context
 * for evidence aggregation and analysis.
 *
 * Ratio convention: realizedPnlPct stored as ratio
 * Example: 0.30 = +30%, -0.12 = -12%
 */
final readonly class EvidenceTradeSample
{
    /**
     * @param int $campaignId Trade campaign ID
     * @param int $instrumentId Instrument ID
     * @param string $tradeType Trade type (live, paper, pseudo)
     * @param string $campaignState Final campaign state (closed_profit, closed_loss, closed_neutral, etc.)
     * @param DateTimeImmutable $openedAt Campaign open timestamp
     * @param DateTimeImmutable|null $closedAt Campaign close timestamp (null if still open)
     * @param int|null $holdingDays Number of days position was held
     * @param string $totalQuantity Total quantity transacted (as string for precision)
     * @param string|null $openQuantity Remaining open quantity (as string for precision)
     * @param string|null $avgEntryPrice Average entry price (as string for precision)
     * @param string|null $realizedPnlGross Realized P&L gross of fees (monetary amount, not ratio)
     * @param string|null $realizedPnlNet Realized P&L net of fees (monetary amount, not ratio)
     * @param string|null $realizedPnlPct Realized P&L percentage (ratio format)
     * @param int|null $entryEventId Entry event ID
     * @param int|null $exitEventId Exit event ID
     * @param string|null $exitReason Exit reason (signal, stop_loss, time_based, etc.)
     * @param int|null $buySignalSnapshotId Associated buy-signal snapshot ID
     * @param int|null $sepaSnapshotId Associated SEPA snapshot ID
     * @param int|null $epaSnapshotId Associated EPA snapshot ID
     * @param string|null $scoringVersion Scoring system version
     * @param string|null $policyVersion Policy version applied
     * @param string|null $modelVersion Model version applied
     * @param string|null $macroVersion Macro version applied
     * @param string|null $seedSource Seed source (live, migration, manual, null)
     * @param EvidenceEligibilityStatus|null $eligibilityStatus Eligibility for aggregation
     * @param EvidenceExclusionReason|null $exclusionReason Reason for exclusion (if excluded)
     * @param EvidenceDataQualityFlag[] $dataQualityFlags Quality flags for this sample
     */
    public function __construct(
        public int $campaignId,
        public int $instrumentId,
        public string $tradeType,
        public string $campaignState,
        public DateTimeImmutable $openedAt,
        public ?DateTimeImmutable $closedAt,
        public ?int $holdingDays,
        public string $totalQuantity,
        public ?string $openQuantity,
        public ?string $avgEntryPrice,
        public ?string $realizedPnlGross,
        public ?string $realizedPnlNet,
        public ?string $realizedPnlPct,
        public ?int $entryEventId,
        public ?int $exitEventId,
        public ?string $exitReason,
        public ?int $buySignalSnapshotId,
        public ?int $sepaSnapshotId,
        public ?int $epaSnapshotId,
        public ?string $scoringVersion,
        public ?string $policyVersion,
        public ?string $modelVersion,
        public ?string $macroVersion,
        public ?string $seedSource,
        public ?EvidenceEligibilityStatus $eligibilityStatus,
        public ?EvidenceExclusionReason $exclusionReason,
        public array $dataQualityFlags = [],
    ) {
    }
}
