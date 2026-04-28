<?php

declare(strict_types=1);

namespace App\Tests\Service\Evidence;

use App\Service\Evidence\EvidenceConfidenceCalculator;
use App\Service\Evidence\EvidenceEligibilityEvaluator;
use App\Service\Evidence\EntryEvidenceAggregator;
use App\Tests\Service\Evidence\Fixture\EvidenceTradeSampleFixture;
use PHPUnit\Framework\TestCase;

/**
 * Tests for EntryEvidenceAggregator.
 *
 * Validates aggregation of trade outcome evidence by entry context buckets,
 * with proper eligibility handling and outcome metrics.
 */
final class EntryEvidenceAggregatorTest extends TestCase
{
    private EntryEvidenceAggregator $aggregator;

    protected function setUp(): void
    {
        $evaluator = new EvidenceEligibilityEvaluator();
        $confidenceCalculator = new EvidenceConfidenceCalculator();
        $this->aggregator = new EntryEvidenceAggregator($evaluator, $confidenceCalculator);
    }

    // =================================================================
    // Core Aggregation Tests (1-11)
    // =================================================================

    /** Test 1: Aggregator counts only eligible_full and eligible_outcome_only for outcome metrics */
    public function testAggregatorCountsOnlyEligibleForOutcomeMetrics(): void
    {
        $samples = [
            EvidenceTradeSampleFixture::closedProfit(), // eligible_outcome_only: missing snapshots
            EvidenceTradeSampleFixture::migrationSeed(['realizedPnlPct' => '0.10']), // eligible_outcome_only
            EvidenceTradeSampleFixture::openCampaign(), // excluded
        ];

        $results = $this->aggregator->aggregateByEntryBucket($samples);

        // Should have 3 buckets: live|live|eligible_outcome_only, live|live|excluded, live|migration|eligible_outcome_only
        self::assertCount(3, $results);

        // Find the live|live|eligible_outcome_only bucket
        $liveOutcomeBucket = $this->findBucket($results, 'live', 'live', 'eligible_outcome_only');
        self::assertNotNull($liveOutcomeBucket);
        self::assertSame(1, $liveOutcomeBucket->sampleCount); // closedProfit
        self::assertSame(0, $liveOutcomeBucket->excludedCount);

        // Find the live|live|excluded bucket
        $liveExcludedBucket = $this->findBucket($results, 'live', 'live', 'excluded');
        self::assertNotNull($liveExcludedBucket);
        self::assertSame(0, $liveExcludedBucket->sampleCount);
        self::assertSame(1, $liveExcludedBucket->excludedCount); // openCampaign

        // Find the live|migration|eligible_outcome_only bucket
        $migrationBucket = $this->findBucket($results, 'live', 'migration', 'eligible_outcome_only');
        self::assertNotNull($migrationBucket);
        self::assertSame(1, $migrationBucket->sampleCount);
    }

    /** Test 2: Excluded samples are not counted in avg/win/loss/neutral */
    public function testExcludedSamplesNotInOutcomeMetrics(): void
    {
        $samples = [
            EvidenceTradeSampleFixture::closedProfit(['realizedPnlPct' => '0.20']), // eligible_outcome_only
            EvidenceTradeSampleFixture::openCampaign(), // excluded
        ];

        $results = $this->aggregator->aggregateByEntryBucket($samples);

        // Outcome bucket should not include excluded
        $outcomeBucket = $this->findBucket($results, 'live', 'live', 'eligible_outcome_only');
        self::assertNotNull($outcomeBucket);
        self::assertSame(1, $outcomeBucket->sampleCount); // Only the profit sample
        self::assertSame(0, $outcomeBucket->excludedCount);
        self::assertSame('0.2', $outcomeBucket->avgRealizedPnlPct); // Not affected by excluded

        // Excluded bucket should track excluded separately
        $excludedBucket = $this->findBucket($results, 'live', 'live', 'excluded');
        self::assertNotNull($excludedBucket);
        self::assertSame(0, $excludedBucket->sampleCount);
        self::assertSame(1, $excludedBucket->excludedCount);
    }

    /** Test 3: Profit + Loss + Neutral = correct counts and rates */
    public function testProfitLossNeutralCalculatesCorrectRates(): void
    {
        $samples = [
            EvidenceTradeSampleFixture::closedProfit(['realizedPnlPct' => '0.15']), // eligible_outcome_only
            EvidenceTradeSampleFixture::closedLoss(['realizedPnlPct' => '-0.08']), // eligible_outcome_only
            EvidenceTradeSampleFixture::closedNeutral(), // eligible_outcome_only
        ];

        $results = $this->aggregator->aggregateByEntryBucket($samples);
        $bucket = $this->findBucket($results, 'live', 'live', 'eligible_outcome_only');

        self::assertNotNull($bucket);
        self::assertSame(3, $bucket->sampleCount);
        self::assertSame('eligible_outcome_only', $bucket->eligibilityStatus);

        // Rates should be 1/3 each
        self::assertSame('0.3333333333', $bucket->winRate);
        self::assertSame('0.3333333333', $bucket->lossRate);
        self::assertSame('0.3333333333', $bucket->neutralRate);
    }

    /** Test 4: avgRealizedPnlPct is correct as ratio */
    public function testAveragePnlCalculatedCorrectly(): void
    {
        $samples = [
            EvidenceTradeSampleFixture::closedProfit(['realizedPnlPct' => '0.15']),
            EvidenceTradeSampleFixture::closedLoss(['realizedPnlPct' => '-0.08']),
            EvidenceTradeSampleFixture::closedNeutral(),
        ];

        $results = $this->aggregator->aggregateByEntryBucket($samples);
        $bucket = $this->findBucket($results, 'live', 'live', 'eligible_outcome_only');

        self::assertNotNull($bucket);
        // (0.15 + (-0.08) + 0.00) / 3 = 0.023333...
        self::assertSame('0.0233333333', $bucket->avgRealizedPnlPct);
    }

    /** Test 5: min/max realizedPnlPct correct */
    public function testMinMaxPnlCalculatedCorrectly(): void
    {
        $samples = [
            EvidenceTradeSampleFixture::closedProfit(['realizedPnlPct' => '0.15']),
            EvidenceTradeSampleFixture::closedLoss(['realizedPnlPct' => '-0.08']),
            EvidenceTradeSampleFixture::closedNeutral(),
        ];

        $results = $this->aggregator->aggregateByEntryBucket($samples);
        $bucket = $this->findBucket($results, 'live', 'live', 'eligible_outcome_only');

        self::assertNotNull($bucket);
        self::assertSame('-0.08', $bucket->minRealizedPnlPct);
        self::assertSame('0.15', $bucket->maxRealizedPnlPct);
    }

    /** Test 6: openCampaign is excluded and not in outcome count */
    public function testOpenCampaignExcluded(): void
    {
        $samples = [
            EvidenceTradeSampleFixture::closedProfit(), // eligible_outcome_only
            EvidenceTradeSampleFixture::openCampaign(), // excluded
        ];

        $results = $this->aggregator->aggregateByEntryBucket($samples);

        // Outcome bucket should only have closedProfit
        $outcomeBucket = $this->findBucket($results, 'live', 'live', 'eligible_outcome_only');
        self::assertNotNull($outcomeBucket);
        self::assertSame(1, $outcomeBucket->sampleCount);
        self::assertSame(0, $outcomeBucket->excludedCount);

        // Excluded bucket should have openCampaign
        $excludedBucket = $this->findBucket($results, 'live', 'live', 'excluded');
        self::assertNotNull($excludedBucket);
        self::assertSame(0, $excludedBucket->sampleCount);
        self::assertSame(1, $excludedBucket->excludedCount);
    }

    /** Test 7: invalidTimeOrder is excluded and not in outcome count */
    public function testInvalidTimeOrderExcluded(): void
    {
        $samples = [
            EvidenceTradeSampleFixture::closedProfit(), // eligible_outcome_only
            EvidenceTradeSampleFixture::invalidTimeOrder([
                'campaignState' => 'closed_profit',
                'realizedPnlPct' => '0.10',
            ]), // excluded
        ];

        $results = $this->aggregator->aggregateByEntryBucket($samples);

        $outcomeBucket = $this->findBucket($results, 'live', 'live', 'eligible_outcome_only');
        self::assertNotNull($outcomeBucket);
        self::assertSame(1, $outcomeBucket->sampleCount);

        $excludedBucket = $this->findBucket($results, 'live', 'live', 'excluded');
        self::assertNotNull($excludedBucket);
        self::assertSame(0, $excludedBucket->sampleCount);
        self::assertSame(1, $excludedBucket->excludedCount);
    }

    /** Test 8: missingPnl is excluded and not in outcome count */
    public function testMissingPnlExcluded(): void
    {
        $samples = [
            EvidenceTradeSampleFixture::closedProfit(), // eligible_outcome_only
            EvidenceTradeSampleFixture::missingPnl(['campaignState' => 'closed_profit']), // excluded
        ];

        $results = $this->aggregator->aggregateByEntryBucket($samples);

        $outcomeBucket = $this->findBucket($results, 'live', 'live', 'eligible_outcome_only');
        self::assertNotNull($outcomeBucket);
        self::assertSame(1, $outcomeBucket->sampleCount);

        $excludedBucket = $this->findBucket($results, 'live', 'live', 'excluded');
        self::assertNotNull($excludedBucket);
        self::assertSame(0, $excludedBucket->sampleCount);
        self::assertSame(1, $excludedBucket->excludedCount);
    }

    /** Test 9: migrationSeed is counted as eligible_outcome_only */
    public function testMigrationSeedCountedAsOutcomeOnly(): void
    {
        $samples = [
            EvidenceTradeSampleFixture::migrationSeed(['realizedPnlPct' => '0.10']),
        ];

        $results = $this->aggregator->aggregateByEntryBucket($samples);
        $bucket = $this->findBucket($results, 'live', 'migration', 'eligible_outcome_only');

        self::assertNotNull($bucket);
        self::assertSame(1, $bucket->sampleCount);
        self::assertSame(0, $bucket->excludedCount);
        self::assertSame('eligible_outcome_only', $bucket->eligibilityStatus);
    }

    /** Test 10: confidenceLevel calculated from sampleCount (n=3 → anecdotal) */
    public function testConfidenceLevelCalculatedFromSampleCount(): void
    {
        $samples = [
            EvidenceTradeSampleFixture::closedProfit(),
            EvidenceTradeSampleFixture::closedLoss(),
            EvidenceTradeSampleFixture::closedNeutral(),
        ];

        $results = $this->aggregator->aggregateByEntryBucket($samples);
        $bucket = $this->findBucket($results, 'live', 'live', 'eligible_outcome_only');

        self::assertNotNull($bucket);
        self::assertSame(3, $bucket->sampleCount);
        self::assertTrue($bucket->confidenceLevel->isAnecdotal());
    }

    /** Test 11: Grouping separates tradeType or seedSource properly */
    public function testGroupingSeparatesBuckets(): void
    {
        $samples = [
            EvidenceTradeSampleFixture::closedProfit(['tradeType' => 'live', 'seedSource' => 'live']),
            EvidenceTradeSampleFixture::migrationSeed(['tradeType' => 'live', 'realizedPnlPct' => '0.10']),
        ];

        $results = $this->aggregator->aggregateByEntryBucket($samples);

        // Should be 2 separate buckets (different seedSource, both eligible_outcome_only)
        self::assertCount(2, $results);

        $liveBucket = $this->findBucket($results, 'live', 'live', 'eligible_outcome_only');
        $migrationBucket = $this->findBucket($results, 'live', 'migration', 'eligible_outcome_only');

        self::assertNotNull($liveBucket);
        self::assertNotNull($migrationBucket);
        self::assertSame('live', $liveBucket->seedSource);
        self::assertSame('migration', $migrationBucket->seedSource);
    }

    // =================================================================
    // Edge Cases
    // =================================================================

    /** Empty samples returns empty array */
    public function testEmptySamplesReturnsEmptyArray(): void
    {
        $results = $this->aggregator->aggregateByEntryBucket([]);

        self::assertSame([], $results);
    }

    /** All excluded samples still creates bucket with excluded count */
    public function testAllExcludedStillCreatesBucket(): void
    {
        $samples = [
            EvidenceTradeSampleFixture::openCampaign(),
            EvidenceTradeSampleFixture::openCampaign(),
        ];

        $results = $this->aggregator->aggregateByEntryBucket($samples);

        self::assertCount(1, $results);
        self::assertSame('excluded', $results[0]->eligibilityStatus);
        self::assertSame(0, $results[0]->sampleCount);
        self::assertSame(2, $results[0]->excludedCount);
    }

    /** Test 12: eligible_outcome_only and excluded are separate buckets */
    public function testEligibleAndExcludedAreSeparateBuckets(): void
    {
        $samples = [
            EvidenceTradeSampleFixture::closedProfit(), // eligible_outcome_only
            EvidenceTradeSampleFixture::openCampaign(), // excluded
        ];

        $results = $this->aggregator->aggregateByEntryBucket($samples);

        // Should have 2 separate buckets for same tradeType|seedSource
        self::assertCount(2, $results);

        // Outcome bucket
        $outcomeBucket = $this->findBucket($results, 'live', 'live', 'eligible_outcome_only');
        self::assertNotNull($outcomeBucket);
        self::assertSame(1, $outcomeBucket->sampleCount);
        self::assertSame(0, $outcomeBucket->excludedCount);
        self::assertSame('eligible_outcome_only', $outcomeBucket->eligibilityStatus);

        // Excluded bucket
        $excludedBucket = $this->findBucket($results, 'live', 'live', 'excluded');
        self::assertNotNull($excludedBucket);
        self::assertSame(0, $excludedBucket->sampleCount);
        self::assertSame(1, $excludedBucket->excludedCount);
        self::assertSame('excluded', $excludedBucket->eligibilityStatus);
    }

    // =================================================================
    // Helper Methods
    // =================================================================

    /**
     * Find a bucket by tradeType, seedSource, and optional eligibilityStatus.
     *
     * @param array<EntryEvidenceBucketSummary> $results
     */
    private function findBucket(
        array $results,
        string $tradeType,
        string $seedSource,
        ?string $eligibilityStatus = null
    ): ?object {
        foreach ($results as $result) {
            if ($result->tradeType === $tradeType && $result->seedSource === $seedSource) {
                if ($eligibilityStatus === null || $result->eligibilityStatus === $eligibilityStatus) {
                    return $result;
                }
            }
        }

        return null;
    }
}