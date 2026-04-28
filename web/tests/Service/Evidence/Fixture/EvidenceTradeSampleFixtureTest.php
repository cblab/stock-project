<?php

declare(strict_types=1);

namespace App\Tests\Service\Evidence\Fixture;

use App\Service\Evidence\EvidenceEligibilityEvaluator;
use PHPUnit\Framework\TestCase;

/**
 * Tests for EvidenceTradeSampleFixture.
 *
 * Validates that fixtures produce correct sample structures
 * and behave as expected when evaluated by EvidenceEligibilityEvaluator.
 */
final class EvidenceTradeSampleFixtureTest extends TestCase
{
    private EvidenceEligibilityEvaluator $evaluator;

    protected function setUp(): void
    {
        $this->evaluator = new EvidenceEligibilityEvaluator();
    }

    // =================================================================
    // Basic Fixture Structure Tests (1-10)
    // =================================================================

    /** Test 1: closedProfit() creates terminal sample with realizedPnlPct > 0 */
    public function testClosedProfitCreatesPositivePnl(): void
    {
        $sample = EvidenceTradeSampleFixture::closedProfit();

        self::assertSame('closed_profit', $sample->campaignState);
        self::assertGreaterThan(0.0, (float) $sample->realizedPnlPct);
    }

    /** Test 2: closedLoss() creates terminal sample with realizedPnlPct < 0 */
    public function testClosedLossCreatesNegativePnl(): void
    {
        $sample = EvidenceTradeSampleFixture::closedLoss();

        self::assertSame('closed_loss', $sample->campaignState);
        self::assertLessThan(0.0, (float) $sample->realizedPnlPct);
    }

    /** Test 3: closedNeutral() creates terminal sample with realizedPnlPct = 0 */
    public function testClosedNeutralCreatesZeroPnl(): void
    {
        $sample = EvidenceTradeSampleFixture::closedNeutral();

        self::assertSame('closed_neutral', $sample->campaignState);
        // neutral has near-zero, not exactly 0, due to fixture using 0.001
        self::assertLessThan(0.01, abs((float) $sample->realizedPnlPct));
    }

    /** Test 4: migrationSeed() sets seedSource = migration */
    public function testMigrationSeedSetsSeedSource(): void
    {
        $sample = EvidenceTradeSampleFixture::migrationSeed();

        self::assertSame('migration', $sample->seedSource);
    }

    /** Test 5: manualSeed() sets seedSource = manual */
    public function testManualSeedSetsSeedSource(): void
    {
        $sample = EvidenceTradeSampleFixture::manualSeed();

        self::assertSame('manual', $sample->seedSource);
    }

    /** Test 6: missingSnapshots() has all snapshot IDs null */
    public function testMissingSnapshotsHasNullIds(): void
    {
        $sample = EvidenceTradeSampleFixture::missingSnapshots();

        self::assertNull($sample->buySignalSnapshotId);
        self::assertNull($sample->sepaSnapshotId);
        self::assertNull($sample->epaSnapshotId);
    }

    /** Test 7: withSnapshotIds() sets all three snapshot IDs */
    public function testWithSnapshotIdsSetsAllIds(): void
    {
        $sample = EvidenceTradeSampleFixture::withSnapshotIds();

        self::assertNotNull($sample->buySignalSnapshotId);
        self::assertNotNull($sample->sepaSnapshotId);
        self::assertNotNull($sample->epaSnapshotId);
    }

    /** Test 8: invalidTimeOrder() has closedAt < openedAt */
    public function testInvalidTimeOrderHasNegativeDuration(): void
    {
        $sample = EvidenceTradeSampleFixture::invalidTimeOrder();

        self::assertNotNull($sample->closedAt);
        self::assertTrue($sample->closedAt < $sample->openedAt);
        self::assertLessThan(0, $sample->holdingDays);
    }

    /** Test 9: missingPnl() has realizedPnlPct = null */
    public function testMissingPnlHasNullPnl(): void
    {
        $sample = EvidenceTradeSampleFixture::missingPnl();

        self::assertNull($sample->realizedPnlPct);
        self::assertNull($sample->realizedPnlGross);
        self::assertNull($sample->realizedPnlNet);
    }

    /** Test 10: openCampaign() has campaignState = open */
    public function testOpenCampaignHasOpenState(): void
    {
        $sample = EvidenceTradeSampleFixture::openCampaign();

        self::assertSame('open', $sample->campaignState);
    }

    // =================================================================
    // Eligibility Integration Tests
    // =================================================================

    /** migrationSeed() → eligible_outcome_only */
    public function testMigrationSeedEvaluatesToEligibleOutcomeOnly(): void
    {
        $sample = EvidenceTradeSampleFixture::migrationSeed();

        $result = $this->evaluator->evaluateTradeSample($sample);

        self::assertTrue(
            $result->status->isEligible(),
            'Migration seed should be eligible (not excluded)'
        );
        self::assertFalse(
            $result->status->isEligibleFull(),
            'Migration seed should not be eligible_full'
        );
    }

    /** invalidTimeOrder() → excluded */
    public function testInvalidTimeOrderEvaluatesToExcluded(): void
    {
        $sample = EvidenceTradeSampleFixture::invalidTimeOrder([
            'campaignState' => 'closed_profit', // Ensure terminal state
            'realizedPnlPct' => '0.10',
        ]);

        $result = $this->evaluator->evaluateTradeSample($sample);

        self::assertTrue(
            $result->status->isExcluded(),
            'Invalid time order should be excluded'
        );
    }

    /** withSnapshotIds() → not eligible_full (snapshot_incomplete flag expected) */
    public function testWithSnapshotIdsNotEligibleFull(): void
    {
        $sample = EvidenceTradeSampleFixture::withSnapshotIds([
            'seedSource' => 'live', // Ensure not migration/manual
        ]);

        $result = $this->evaluator->evaluateTradeSample($sample);

        // Currently with snapshot IDs but without full DB validation
        // results in eligible_outcome_only (not eligible_full)
        self::assertFalse(
            $result->status->isEligibleFull(),
            'Sample with only snapshot IDs (unvalidated) should not be eligible_full'
        );
    }

    /** openCampaign() → excluded */
    public function testOpenCampaignEvaluatesToExcluded(): void
    {
        $sample = EvidenceTradeSampleFixture::openCampaign();

        $result = $this->evaluator->evaluateTradeSample($sample);

        self::assertTrue(
            $result->status->isExcluded(),
            'Open campaign should be excluded'
        );
    }

    // =================================================================
    // Determinism Tests
    // =================================================================

    /** Fixtures produce deterministic values */
    public function testFixturesAreDeterministic(): void
    {
        $sample1 = EvidenceTradeSampleFixture::closedProfit();
        $sample2 = EvidenceTradeSampleFixture::closedProfit();

        self::assertEquals($sample1, $sample2);
    }

    /** Override mechanism works correctly */
    public function testOverridesAreApplied(): void
    {
        $sample = EvidenceTradeSampleFixture::closedProfit([
            'campaignId' => 9999,
            'realizedPnlPct' => '0.50',
        ]);

        self::assertSame(9999, $sample->campaignId);
        self::assertSame('0.50', $sample->realizedPnlPct);
    }
}
