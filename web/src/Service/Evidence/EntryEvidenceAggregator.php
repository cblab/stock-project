<?php

declare(strict_types=1);

namespace App\Service\Evidence;

use App\Service\Evidence\Model\EntryEvidenceBucketSummary;
use App\Service\Evidence\Model\EvidenceConfidenceLevel;
use App\Service\Evidence\Model\EvidenceTradeSample;

/**
 * Entry Evidence Aggregator for trade outcome samples.
 *
 * Aggregates EvidenceTradeSample data by entry/signal context buckets.
 * Calculates outcome metrics (avg PnL, win/loss/neutral rates, confidence)
 * while respecting eligibility rules.
 *
 * C4 Scope:
 * - Groups by tradeType, seedSource, eligibility status
 * - Counts eligible_full and eligible_outcome_only for outcome metrics
 * - Separately tracks excluded samples
 * - Uses EvidenceConfidenceCalculator for confidence levels
 * - No DB queries, no recommendations, no predictions
 *
 * Ratio convention: realizedPnlPct as ratio (0.30 = +30%, -0.12 = -12%)
 */
final readonly class EntryEvidenceAggregator
{
    public function __construct(
        private EvidenceEligibilityEvaluator $eligibilityEvaluator,
        private EvidenceConfidenceCalculator $confidenceCalculator,
    ) {
    }

    /**
     * Aggregate samples by entry context buckets.
     *
     * Groups samples by:
     * - tradeType (live, paper, pseudo)
     * - seedSource (live, migration, manual)
     *
     * eligible_full and eligible_outcome_only are combined for outcome metrics.
     * excluded is tracked separately but not included in outcome metrics.
     *
     * @param EvidenceTradeSample[] $samples Raw trade samples to aggregate
     *
     * @return EntryEvidenceBucketSummary[] Aggregated bucket summaries
     */
    public function aggregateByEntryBucket(array $samples): array
    {
        // First pass: evaluate all samples and group by bucket key
        $buckets = [];

        foreach ($samples as $sample) {
            $evaluation = $this->eligibilityEvaluator->evaluateTradeSample($sample);
            $bucketKey = $this->buildBucketKey($sample);

            if (!isset($buckets[$bucketKey])) {
                $buckets[$bucketKey] = [
                    'tradeType' => $sample->tradeType,
                    'seedSource' => $sample->seedSource ?? 'live',
                    'eligibleFull' => [],
                    'eligibleOutcomeOnly' => [],
                    'excluded' => [],
                ];
            }

            if ($evaluation->status->isExcluded()) {
                $buckets[$bucketKey]['excluded'][] = ['sample' => $sample, 'evaluation' => $evaluation];
            } elseif ($evaluation->status->isEligibleFull()) {
                $buckets[$bucketKey]['eligibleFull'][] = ['sample' => $sample, 'evaluation' => $evaluation];
            } else {
                // eligible_outcome_only
                $buckets[$bucketKey]['eligibleOutcomeOnly'][] = ['sample' => $sample, 'evaluation' => $evaluation];
            }
        }

        // Second pass: calculate metrics for each bucket
        $summaries = [];
        foreach ($buckets as $bucketKey => $bucketData) {
            $summaries[] = $this->calculateBucketSummary($bucketKey, $bucketData);
        }

        return $summaries;
    }

    /**
     * Build a unique bucket key for grouping.
     */
    private function buildBucketKey(EvidenceTradeSample $sample): string
    {
        $tradeType = $sample->tradeType;
        $seedSource = $sample->seedSource ?? 'live';

        return sprintf('%s|%s', $tradeType, $seedSource);
    }

    /**
     * Calculate summary metrics for a single bucket.
     *
     * @param string $bucketKey Technical bucket identifier
     * @param array<string, mixed> $bucketData Grouped sample data
     */
    private function calculateBucketSummary(string $bucketKey, array $bucketData): EntryEvidenceBucketSummary
    {
        // Count by eligibility category
        $eligibleFullCount = count($bucketData['eligibleFull']);
        $eligibleOutcomeOnlyCount = count($bucketData['eligibleOutcomeOnly']);
        $sampleCount = $eligibleFullCount + $eligibleOutcomeOnlyCount;
        $excludedCount = count($bucketData['excluded']);

        if ($sampleCount === 0) {
            return new EntryEvidenceBucketSummary(
                bucketKey: $bucketKey,
                tradeType: $bucketData['tradeType'],
                seedSource: $bucketData['seedSource'],
                sampleCount: 0,
                eligibleFullCount: 0,
                eligibleOutcomeOnlyCount: 0,
                excludedCount: $excludedCount,
                avgRealizedPnlPct: null,
                winRate: null,
                lossRate: null,
                neutralRate: null,
                minRealizedPnlPct: null,
                maxRealizedPnlPct: null,
                confidenceLevel: EvidenceConfidenceLevel::anecdotal(),
            );
        }

        // Combine eligible samples for outcome metrics
        $outcomeSamples = array_merge(
            $bucketData['eligibleFull'],
            $bucketData['eligibleOutcomeOnly'],
        );

        // Calculate outcome metrics
        $returns = [];
        $winCount = 0;
        $lossCount = 0;
        $neutralCount = 0;

        foreach ($outcomeSamples as $item) {
            $sample = $item['sample'];

            // Null PnL in eligible outcome samples is an invariant violation
            if ($sample->realizedPnlPct === null) {
                throw new \LogicException('Eligible outcome sample must have realizedPnlPct.');
            }

            $pnl = (float) $sample->realizedPnlPct;
            $returns[] = $pnl;

            if ($pnl > 0) {
                ++$winCount;
            } elseif ($pnl < 0) {
                ++$lossCount;
            } else {
                ++$neutralCount;
            }
        }

        $avgReturn = array_sum($returns) / $sampleCount;
        $minReturn = min($returns);
        $maxReturn = max($returns);

        $winRate = $winCount / $sampleCount;
        $lossRate = $lossCount / $sampleCount;
        $neutralRate = $neutralCount / $sampleCount;

        // Calculate confidence (SEM optional, skip for C4 scope)
        $confidenceLevel = $this->confidenceCalculator->calculate($sampleCount, null);

        return new EntryEvidenceBucketSummary(
            bucketKey: $bucketKey,
            tradeType: $bucketData['tradeType'],
            seedSource: $bucketData['seedSource'],
            sampleCount: $sampleCount,
            eligibleFullCount: $eligibleFullCount,
            eligibleOutcomeOnlyCount: $eligibleOutcomeOnlyCount,
            excludedCount: $excludedCount,
            avgRealizedPnlPct: $this->formatRatio($avgReturn),
            winRate: $this->formatRatio($winRate),
            lossRate: $this->formatRatio($lossRate),
            neutralRate: $this->formatRatio($neutralRate),
            minRealizedPnlPct: $this->formatRatio($minReturn),
            maxRealizedPnlPct: $this->formatRatio($maxReturn),
            confidenceLevel: $confidenceLevel,
        );
    }

    /**
     * Format a float ratio as string with consistent precision.
     */
    private function formatRatio(float $value): string
    {
        // Use sufficient precision for ratios, trim trailing zeros
        return rtrim(rtrim(sprintf('%.10f', $value), '0'), '.');
    }
}
