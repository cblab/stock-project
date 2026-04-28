<?php

declare(strict_types=1);

namespace App\Service\Evidence;

use App\Service\Evidence\Model\EvidenceConfidenceLevel;
use App\Service\Evidence\Model\EvidenceTradeSample;
use App\Service\Evidence\Model\ExitEvidenceBucketSummary;

/**
 * Exit Evidence Aggregator for trade outcome samples.
 *
 * Aggregates EvidenceTradeSample data by exit context buckets.
 * Calculates outcome metrics (avg PnL, win/loss/neutral rates, confidence)
 * while respecting eligibility rules.
 *
 * C5 Scope:
 * - Groups by exitReason, campaignState, tradeType, seedSource
 * - Counts eligible_full and eligible_outcome_only for outcome metrics
 * - Separately tracks excluded samples as bucket composition
 * - Uses EvidenceConfidenceCalculator for confidence levels
 * - No DB queries, no predictions, no recommendations
 *
 * Ratio convention: realizedPnlPct as ratio (0.30 = +30%, -0.12 = -12%)
 */
final readonly class ExitEvidenceAggregator
{
    public function __construct(
        private EvidenceEligibilityEvaluator $eligibilityEvaluator,
        private EvidenceConfidenceCalculator $confidenceCalculator,
    ) {
    }

    /**
     * Aggregate samples by exit context buckets.
     *
     * Groups samples by:
     * - exitReason
     * - campaignState
     * - tradeType
     * - seedSource
     *
     * eligible_full and eligible_outcome_only are combined for outcome metrics.
     * excluded is tracked separately but not included in outcome metrics.
     *
     * @param EvidenceTradeSample[] $samples Raw trade samples to aggregate
     *
     * @return ExitEvidenceBucketSummary[] Aggregated bucket summaries
     */
    public function aggregateByExitBucket(array $samples): array
    {
        $buckets = [];

        foreach ($samples as $sample) {
            $evaluation = $this->eligibilityEvaluator->evaluateTradeSample($sample);
            $bucketKey = $this->buildBucketKey($sample);

            if (!isset($buckets[$bucketKey])) {
                $buckets[$bucketKey] = [
                    'exitReason' => $sample->exitReason ?? 'unknown',
                    'campaignState' => $sample->campaignState,
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
                $buckets[$bucketKey]['eligibleOutcomeOnly'][] = ['sample' => $sample, 'evaluation' => $evaluation];
            }
        }

        $summaries = [];
        foreach ($buckets as $bucketKey => $bucketData) {
            $summaries[] = $this->calculateBucketSummary($bucketKey, $bucketData);
        }

        return $summaries;
    }

    private function buildBucketKey(EvidenceTradeSample $sample): string
    {
        $exitReason = $sample->exitReason ?? 'unknown';
        $campaignState = $sample->campaignState;
        $tradeType = $sample->tradeType;
        $seedSource = $sample->seedSource ?? 'live';

        return sprintf('%s|%s|%s|%s', $exitReason, $campaignState, $tradeType, $seedSource);
    }

    /**
     * @param string $bucketKey Technical bucket identifier
     * @param array<string, mixed> $bucketData Grouped sample data
     */
    private function calculateBucketSummary(string $bucketKey, array $bucketData): ExitEvidenceBucketSummary
    {
        $eligibleFullCount = count($bucketData['eligibleFull']);
        $eligibleOutcomeOnlyCount = count($bucketData['eligibleOutcomeOnly']);
        $sampleCount = $eligibleFullCount + $eligibleOutcomeOnlyCount;
        $excludedCount = count($bucketData['excluded']);

        if ($sampleCount === 0) {
            return new ExitEvidenceBucketSummary(
                bucketKey: $bucketKey,
                exitReason: $bucketData['exitReason'],
                campaignState: $bucketData['campaignState'],
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

        $outcomeSamples = array_merge(
            $bucketData['eligibleFull'],
            $bucketData['eligibleOutcomeOnly'],
        );

        $returns = [];
        $winCount = 0;
        $lossCount = 0;
        $neutralCount = 0;

        foreach ($outcomeSamples as $item) {
            $sample = $item['sample'];

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

        $confidenceLevel = $this->confidenceCalculator->calculate($sampleCount, null);

        return new ExitEvidenceBucketSummary(
            bucketKey: $bucketKey,
            exitReason: $bucketData['exitReason'],
            campaignState: $bucketData['campaignState'],
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

    private function formatRatio(float $value): string
    {
        return rtrim(rtrim(sprintf('%.10f', $value), '0'), '.');
    }
}
