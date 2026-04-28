<?php

declare(strict_types=1);

namespace App\Tests\Service\Evidence;

use App\Service\Evidence\EvidenceConfidenceCalculator;
use App\Service\Evidence\EvidenceEligibilityEvaluator;
use App\Service\Evidence\ExitEvidenceAggregator;
use App\Service\Evidence\Model\ExitEvidenceBucketSummary;
use App\Tests\Service\Evidence\Fixture\EvidenceTradeSampleFixture;
use PHPUnit\Framework\TestCase;

final class ExitEvidenceAggregatorTest extends TestCase
{
    private ExitEvidenceAggregator $aggregator;

    protected function setUp(): void
    {
        $evaluator = new EvidenceEligibilityEvaluator();
        $confidenceCalculator = new EvidenceConfidenceCalculator();
        $this->aggregator = new ExitEvidenceAggregator($evaluator, $confidenceCalculator);
    }

    public function testGroupsByExitReason(): void
    {
        $samples = [
            EvidenceTradeSampleFixture::closedProfit(['exitReason' => 'signal']),
            EvidenceTradeSampleFixture::closedProfit(['exitReason' => 'stop_loss']),
        ];

        $results = $this->aggregator->aggregateByExitBucket($samples);

        self::assertCount(2, $results);
        self::assertNotNull($this->findBucket($results, 'signal', 'closed_profit', 'live', 'live'));
        self::assertNotNull($this->findBucket($results, 'stop_loss', 'closed_profit', 'live', 'live'));
    }

    public function testGroupsByCampaignState(): void
    {
        $samples = [
            EvidenceTradeSampleFixture::closedProfit(['exitReason' => 'signal']),
            EvidenceTradeSampleFixture::closedLoss(['exitReason' => 'signal']),
        ];

        $results = $this->aggregator->aggregateByExitBucket($samples);

        self::assertCount(2, $results);
        self::assertNotNull($this->findBucket($results, 'signal', 'closed_profit', 'live', 'live'));
        self::assertNotNull($this->findBucket($results, 'signal', 'closed_loss', 'live', 'live'));
    }

    public function testOutcomeOnlySamplesAreCountedWithComposition(): void
    {
        // C3 produces practically eligible_outcome_only samples here because
        // DB-level snapshot validation for eligible_full is not available yet.
        // This test verifies that C5 counts outcome-only samples correctly and
        // exposes the composition via eligibleFullCount / eligibleOutcomeOnlyCount.
        // TODO: Add explicit eligible_full + eligible_outcome_only same-bucket test
        // once DB-level snapshot validation can produce eligible_full samples.
        $samples = [
            EvidenceTradeSampleFixture::migrationSeed([
                'campaignState' => 'closed_profit',
                'exitReason' => 'signal',
                'realizedPnlPct' => '0.10',
            ]),
            EvidenceTradeSampleFixture::closedProfit([
                'exitReason' => 'signal',
            ]),
        ];

        $results = $this->aggregator->aggregateByExitBucket($samples);
        $bucket = $this->findBucket($results, 'signal', 'closed_profit', 'live', 'migration');
        $liveBucket = $this->findBucket($results, 'signal', 'closed_profit', 'live', 'live');

        self::assertNotNull($bucket);
        self::assertSame(1, $bucket->sampleCount);
        self::assertSame(0, $bucket->eligibleFullCount);
        self::assertSame(1, $bucket->eligibleOutcomeOnlyCount);
        self::assertSame(0, $bucket->excludedCount);

        self::assertNotNull($liveBucket);
        self::assertSame(1, $liveBucket->sampleCount);
        self::assertSame(0, $liveBucket->eligibleFullCount);
        self::assertSame(1, $liveBucket->eligibleOutcomeOnlyCount);
    }

    public function testExcludedSamplesDoNotCountInOutcomeMetrics(): void
    {
        $samples = [
            EvidenceTradeSampleFixture::closedProfit([
                'campaignState' => 'closed_profit',
                'exitReason' => 'signal',
                'tradeType' => 'paper',
                'seedSource' => 'manual',
                'realizedPnlPct' => '0.20',
            ]),
            EvidenceTradeSampleFixture::openCampaign([
                'closedAt' => new \DateTimeImmutable('2024-03-15 14:30:00'),
                'holdingDays' => 60,
                'exitReason' => 'signal',
                'tradeType' => 'paper',
                'seedSource' => 'manual',
            ]),
        ];

        $results = $this->aggregator->aggregateByExitBucket($samples);
        $bucket = $this->findBucket($results, 'signal', 'open', 'paper', 'manual');
        $profitBucket = $this->findBucket($results, 'signal', 'closed_profit', 'paper', 'manual');

        self::assertNotNull($bucket);
        self::assertSame(0, $bucket->sampleCount);
        self::assertSame(1, $bucket->excludedCount);
        self::assertNull($bucket->avgRealizedPnlPct);

        self::assertNotNull($profitBucket);
        self::assertSame(1, $profitBucket->sampleCount);
        self::assertSame('0.2', $profitBucket->avgRealizedPnlPct);
    }

    public function testWinLossNeutralMetricsAreCalculatedCorrectlyWithinSameBucket(): void
    {
        $samples = [
            EvidenceTradeSampleFixture::closedProfit([
                'campaignState' => 'closed_profit',
                'exitReason' => 'signal',
                'realizedPnlPct' => '0.15',
            ]),
            EvidenceTradeSampleFixture::closedLoss([
                'campaignState' => 'closed_profit',
                'exitReason' => 'signal',
                'realizedPnlPct' => '-0.08',
            ]),
            EvidenceTradeSampleFixture::closedNeutral([
                'campaignState' => 'closed_profit',
                'exitReason' => 'signal',
            ]),
        ];

        $results = $this->aggregator->aggregateByExitBucket($samples);
        $bucket = $this->findBucket($results, 'signal', 'closed_profit', 'live', 'live');

        self::assertNotNull($bucket);
        self::assertSame(3, $bucket->sampleCount);
        self::assertSame('0.3333333333', $bucket->winRate);
        self::assertSame('0.3333333333', $bucket->lossRate);
        self::assertSame('0.3333333333', $bucket->neutralRate);
        self::assertSame('0.0233333333', $bucket->avgRealizedPnlPct);
        self::assertSame('-0.08', $bucket->minRealizedPnlPct);
        self::assertSame('0.15', $bucket->maxRealizedPnlPct);
    }

    public function testMissingPnlIsExcludedAndNotCounted(): void
    {
        $samples = [
            EvidenceTradeSampleFixture::closedProfit([
                'campaignState' => 'closed_profit',
                'exitReason' => 'signal',
            ]),
            EvidenceTradeSampleFixture::missingPnl([
                'campaignState' => 'closed_profit',
                'exitReason' => 'signal',
            ]),
        ];

        $results = $this->aggregator->aggregateByExitBucket($samples);

        self::assertCount(1, $results);
        self::assertSame(1, $results[0]->sampleCount);
        self::assertSame(1, $results[0]->excludedCount);
    }

    public function testInvalidTimeOrderIsExcludedAndNotCounted(): void
    {
        $samples = [
            EvidenceTradeSampleFixture::closedProfit([
                'campaignState' => 'closed_profit',
                'exitReason' => 'signal',
            ]),
            EvidenceTradeSampleFixture::invalidTimeOrder([
                'campaignState' => 'closed_profit',
                'exitReason' => 'signal',
                'seedSource' => 'migration',
                'buySignalSnapshotId' => null,
                'sepaSnapshotId' => null,
                'epaSnapshotId' => null,
            ]),
        ];

        $results = $this->aggregator->aggregateByExitBucket($samples);

        self::assertCount(2, $results);
        $liveBucket = $this->findBucket($results, 'signal', 'closed_profit', 'live', 'live');
        $migrationBucket = $this->findBucket($results, 'signal', 'closed_profit', 'live', 'migration');

        self::assertNotNull($liveBucket);
        self::assertSame(1, $liveBucket->sampleCount);

        self::assertNotNull($migrationBucket);
        self::assertSame(0, $migrationBucket->sampleCount);
        self::assertSame(1, $migrationBucket->excludedCount);
    }

    public function testMigrationSeedCountsAsEligibleOutcomeOnly(): void
    {
        $samples = [
            EvidenceTradeSampleFixture::migrationSeed([
                'campaignState' => 'closed_profit',
                'exitReason' => 'signal',
                'realizedPnlPct' => '0.10',
            ]),
        ];

        $results = $this->aggregator->aggregateByExitBucket($samples);
        $bucket = $this->findBucket($results, 'signal', 'closed_profit', 'live', 'migration');

        self::assertNotNull($bucket);
        self::assertSame(1, $bucket->sampleCount);
        self::assertSame(0, $bucket->eligibleFullCount);
        self::assertSame(1, $bucket->eligibleOutcomeOnlyCount);
        self::assertSame(0, $bucket->excludedCount);
    }

    public function testConfidenceLevelUsesSampleCount(): void
    {
        $samples = [
            EvidenceTradeSampleFixture::closedProfit([
                'campaignState' => 'closed_profit',
                'exitReason' => 'signal',
            ]),
            EvidenceTradeSampleFixture::closedLoss([
                'campaignState' => 'closed_profit',
                'exitReason' => 'signal',
            ]),
            EvidenceTradeSampleFixture::closedNeutral([
                'campaignState' => 'closed_profit',
                'exitReason' => 'signal',
            ]),
        ];

        $results = $this->aggregator->aggregateByExitBucket($samples);

        self::assertCount(1, $results);
        self::assertTrue($results[0]->confidenceLevel->isAnecdotal());
    }

    public function testEmptyInputReturnsEmptyArray(): void
    {
        self::assertSame([], $this->aggregator->aggregateByExitBucket([]));
    }

    public function testAllExcludedCreatesBucketWithNullMetrics(): void
    {
        $samples = [
            EvidenceTradeSampleFixture::openCampaign([
                'tradeType' => 'pseudo',
                'seedSource' => 'manual',
                'exitReason' => 'signal',
            ]),
            EvidenceTradeSampleFixture::openCampaign([
                'tradeType' => 'pseudo',
                'seedSource' => 'manual',
                'exitReason' => 'signal',
            ]),
        ];

        $results = $this->aggregator->aggregateByExitBucket($samples);
        $bucket = $this->findBucket($results, 'signal', 'open', 'pseudo', 'manual');

        self::assertNotNull($bucket);
        self::assertSame(0, $bucket->sampleCount);
        self::assertSame(2, $bucket->excludedCount);
        self::assertNull($bucket->avgRealizedPnlPct);
        self::assertNull($bucket->winRate);
        self::assertNull($bucket->lossRate);
        self::assertNull($bucket->neutralRate);
        self::assertNull($bucket->minRealizedPnlPct);
        self::assertNull($bucket->maxRealizedPnlPct);
        self::assertTrue($bucket->confidenceLevel->isAnecdotal());
    }

    /**
     * @param array<ExitEvidenceBucketSummary> $results
     */
    private function findBucket(
        array $results,
        string $exitReason,
        string $campaignState,
        string $tradeType,
        string $seedSource,
    ): ?ExitEvidenceBucketSummary {
        foreach ($results as $result) {
            if ($result->exitReason === $exitReason
                && $result->campaignState === $campaignState
                && $result->tradeType === $tradeType
                && $result->seedSource === $seedSource
            ) {
                return $result;
            }
        }

        return null;
    }
}
