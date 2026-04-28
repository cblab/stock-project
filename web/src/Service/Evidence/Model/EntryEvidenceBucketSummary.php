<?php

declare(strict_types=1);

namespace App\Service\Evidence\Model;

/**
 * Summary of aggregated entry evidence for a single bucket.
 *
 * Immutable DTO representing outcome metrics for a group of
 * EvidenceTradeSample items sharing the same entry context
 * (tradeType, seedSource, etc.).
 *
 * Ratio convention: All percentage values stored as ratios
 * Example: 0.30 = +30%, -0.12 = -12%, 0.65 = 65% win rate
 *
 * C4 Scope: Trade outcome evidence only. Signal evidence (SEPA/EPA)
 * will be handled in future chunks.
 */
final readonly class EntryEvidenceBucketSummary
{
    /**
     * @param string $bucketKey Technical bucket identifier (e.g., "live|live")
     * @param string $tradeType Trade type (live, paper, pseudo)
     * @param string $seedSource Seed source (live, migration, manual)
     * @param int $sampleCount Number of eligible samples in bucket (full + outcome_only)
     * @param int $eligibleFullCount Number of fully eligible samples (with snapshot validation)
     * @param int $eligibleOutcomeOnlyCount Number of outcome-only eligible samples
     * @param int $excludedCount Number of excluded samples
     * @param string|null $avgRealizedPnlPct Average PnL as ratio, null if no samples
     * @param string|null $winRate Win rate as ratio (0.0-1.0), null if no samples
     * @param string|null $lossRate Loss rate as ratio (0.0-1.0), null if no samples
     * @param string|null $neutralRate Neutral rate as ratio (0.0-1.0), null if no samples
     * @param string|null $minRealizedPnlPct Minimum PnL as ratio, null if no samples
     * @param string|null $maxRealizedPnlPct Maximum PnL as ratio, null if no samples
     * @param EvidenceConfidenceLevel $confidenceLevel Qualitative confidence level
     */
    public function __construct(
        public string $bucketKey,
        public string $tradeType,
        public string $seedSource,
        public int $sampleCount,
        public int $eligibleFullCount,
        public int $eligibleOutcomeOnlyCount,
        public int $excludedCount,
        public ?string $avgRealizedPnlPct,
        public ?string $winRate,
        public ?string $lossRate,
        public ?string $neutralRate,
        public ?string $minRealizedPnlPct,
        public ?string $maxRealizedPnlPct,
        public EvidenceConfidenceLevel $confidenceLevel,
    ) {
    }
}
