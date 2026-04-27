<?php

declare(strict_types=1);

namespace App\Service\Evidence\Model;

/**
 * Immutable DTO representing aggregated evidence metrics.
 *
 * Summarizes a collection of evidence samples (trade outcomes or signal forward returns)
 * with statistical measures for decision quality analysis.
 *
 * Signal-source-agnostic: works with any evidence source and any signal source.
 *
 * Ratio convention: All return percentages stored as ratios
 * Example: 0.30 = +30%, -0.12 = -12%
 */
final readonly class EvidenceMetricSummary
{
    /**
     * @param EvidenceSource $evidenceSource Evidence source type (trade_outcome, signal_forward_return)
     * @param SignalSource|null $signalSource Specific signal source (sepa, epa, kronos, etc.) - null for trade outcomes
     * @param SignalFamily|null $signalFamily Signal family category - null for trade outcomes
     * @param string $bucketKey Technical bucket key for filtering/aggregation
     * @param string|null $bucketLabel Human-readable bucket label (e.g., "SEPA Score 75-84")
     * @param string $timeWindow Time window for the aggregation (e.g., "2024-Q1", "last_90d")
     * @param int|null $horizonDays Forward return horizon in days - null for non-forward-return evidence
     * @param int $n Sample size (number of evidence samples in this bucket)
     * @param string|null $avgReturnPct Average return as ratio (e.g., "0.15" = +15% average)
     * @param string|null $minReturnPct Minimum return as ratio
     * @param string|null $maxReturnPct Maximum return as ratio
     * @param string|null $medianReturnPct Median return as ratio
     * @param string|null $standardDeviation Standard deviation of returns as ratio
     * @param string|null $standardErrorOfMean Standard error of the mean as ratio
     * @param float|null $confidenceLevel Confidence level for statistical measures (e.g., 0.95 for 95%)
     * @param EvidenceDataQualityFlag[] $dataQualityFlags Quality flags affecting this summary
     */
    public function __construct(
        public EvidenceSource $evidenceSource,
        public ?SignalSource $signalSource,
        public ?SignalFamily $signalFamily,
        public string $bucketKey,
        public ?string $bucketLabel,
        public string $timeWindow,
        public ?int $horizonDays,
        public int $n,
        public ?string $avgReturnPct,
        public ?string $minReturnPct,
        public ?string $maxReturnPct,
        public ?string $medianReturnPct,
        public ?string $standardDeviation,
        public ?string $standardErrorOfMean,
        public ?float $confidenceLevel,
        public array $dataQualityFlags = [],
    ) {
    }
}
