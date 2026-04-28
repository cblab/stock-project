<?php

declare(strict_types=1);

namespace App\Tests\Service\Evidence;

use App\Service\Evidence\EvidenceEligibilityEvaluator;
use App\Service\Evidence\Model\EvidenceDataQualityFlag;
use App\Service\Evidence\Model\EvidenceEligibilityStatus;
use App\Service\Evidence\Model\EvidenceExclusionReason;
use App\Service\Evidence\Model\EvidenceTradeSample;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class EvidenceEligibilityEvaluatorTest extends TestCase
{
    private EvidenceEligibilityEvaluator $evaluator;

    protected function setUp(): void
    {
        $this->evaluator = new EvidenceEligibilityEvaluator();
    }

    public function testTerminalClosedProfitWithoutSnapshotIsOutcomeOnly(): void
    {
        $sample = $this->createSample([
            'campaignState' => 'closed_profit',
            'realizedPnlPct' => '0.15',
            'seedSource' => 'live',
            'buySignalSnapshotId' => null,
            'sepaSnapshotId' => null,
            'epaSnapshotId' => null,
        ]);

        $result = $this->evaluator->evaluateTradeSample($sample);

        $this->assertEquals(EvidenceEligibilityStatus::eligibleOutcomeOnly(), $result->status);
        $this->assertNull($result->exclusionReason);
        $this->assertContainsEquals(EvidenceDataQualityFlag::missingEntrySnapshot(), $result->dataQualityFlags);
        $this->assertContainsEquals(EvidenceDataQualityFlag::snapshotIncomplete(), $result->dataQualityFlags);
    }

    public function testMigrationSeedIsOutcomeOnlyWithFlags(): void
    {
        $sample = $this->createSample([
            'campaignState' => 'closed_profit',
            'realizedPnlPct' => '0.10',
            'seedSource' => 'migration',
            'buySignalSnapshotId' => 123,
        ]);

        $result = $this->evaluator->evaluateTradeSample($sample);

        $this->assertEquals(EvidenceEligibilityStatus::eligibleOutcomeOnly(), $result->status);
        $this->assertNull($result->exclusionReason);
        $this->assertContainsEquals(EvidenceDataQualityFlag::migrationSeed(), $result->dataQualityFlags);
        $this->assertContainsEquals(EvidenceDataQualityFlag::containsSeedData(), $result->dataQualityFlags);
    }

    public function testManualSeedIsOutcomeOnlyWithFlags(): void
    {
        $sample = $this->createSample([
            'campaignState' => 'closed_loss',
            'realizedPnlPct' => '-0.08',
            'seedSource' => 'manual',
        ]);

        $result = $this->evaluator->evaluateTradeSample($sample);

        $this->assertEquals(EvidenceEligibilityStatus::eligibleOutcomeOnly(), $result->status);
        $this->assertNull($result->exclusionReason);
        $this->assertContainsEquals(EvidenceDataQualityFlag::manualSeed(), $result->dataQualityFlags);
        $this->assertContainsEquals(EvidenceDataQualityFlag::containsSeedData(), $result->dataQualityFlags);
    }

    public function testInvalidTimeOrderIsExcluded(): void
    {
        $sample = $this->createSample([
            'campaignState' => 'closed_profit',
            'openedAt' => new DateTimeImmutable('2024-01-15 10:00:00'),
            'closedAt' => new DateTimeImmutable('2024-01-10 10:00:00'),
            'realizedPnlPct' => '0.05',
            'seedSource' => 'live',
        ]);

        $result = $this->evaluator->evaluateTradeSample($sample);

        $this->assertEquals(EvidenceEligibilityStatus::excluded(), $result->status);
        $this->assertEquals(EvidenceExclusionReason::invalidTimeOrder(), $result->exclusionReason);
    }

    public function testMissingPnlIsExcluded(): void
    {
        $sample = $this->createSample([
            'campaignState' => 'closed_profit',
            'realizedPnlPct' => null,
            'seedSource' => 'live',
        ]);

        $result = $this->evaluator->evaluateTradeSample($sample);

        $this->assertEquals(EvidenceEligibilityStatus::excluded(), $result->status);
        $this->assertEquals(EvidenceExclusionReason::missingPnl(), $result->exclusionReason);
    }

    public function testNonTerminalOpenCampaignIsExcluded(): void
    {
        $sample = $this->createSample(['campaignState' => 'open', 'closedAt' => null]);
        $result = $this->evaluator->evaluateTradeSample($sample);
        $this->assertEquals(EvidenceEligibilityStatus::excluded(), $result->status);
        $this->assertEquals(EvidenceExclusionReason::openCampaign(), $result->exclusionReason);
    }

    public function testNonTerminalTrimmedCampaignIsExcluded(): void
    {
        $sample = $this->createSample(['campaignState' => 'trimmed', 'realizedPnlPct' => '0.05']);
        $result = $this->evaluator->evaluateTradeSample($sample);
        $this->assertEquals(EvidenceEligibilityStatus::excluded(), $result->status);
        $this->assertEquals(EvidenceExclusionReason::openCampaign(), $result->exclusionReason);
    }

    public function testNonTerminalPausedCampaignIsExcluded(): void
    {
        $sample = $this->createSample(['campaignState' => 'paused', 'realizedPnlPct' => '-0.02']);
        $result = $this->evaluator->evaluateTradeSample($sample);
        $this->assertEquals(EvidenceEligibilityStatus::excluded(), $result->status);
        $this->assertEquals(EvidenceExclusionReason::openCampaign(), $result->exclusionReason);
    }

    public function testUnvalidatedSnapshotIdsAreNotEligibleFull(): void
    {
        $sample = $this->createSample([
            'campaignState' => 'closed_profit',
            'realizedPnlPct' => '0.12',
            'seedSource' => 'live',
            'buySignalSnapshotId' => 123,
        ]);

        $result = $this->evaluator->evaluateTradeSample($sample);

        $this->assertNotEquals(EvidenceEligibilityStatus::eligibleFull(), $result->status);
        $this->assertContainsEquals(EvidenceDataQualityFlag::snapshotIncomplete(), $result->dataQualityFlags);
    }

    public function testMissingClosedAtIsExcluded(): void
    {
        $sample = $this->createSample([
            'campaignState' => 'closed_profit',
            'closedAt' => null,
            'realizedPnlPct' => '0.05',
        ]);

        $result = $this->evaluator->evaluateTradeSample($sample);

        $this->assertEquals(EvidenceEligibilityStatus::excluded(), $result->status);
        $this->assertEquals(EvidenceExclusionReason::missingClosedAt(), $result->exclusionReason);
    }

    private function createSample(array $overrides = []): EvidenceTradeSample
    {
        $data = array_merge([
            'campaignId' => 1,
            'instrumentId' => 1,
            'tradeType' => 'live',
            'campaignState' => 'closed_profit',
            'openedAt' => new DateTimeImmutable('2024-01-01 10:00:00'),
            'closedAt' => new DateTimeImmutable('2024-01-10 10:00:00'),
            'holdingDays' => 9,
            'totalQuantity' => '100',
            'openQuantity' => null,
            'avgEntryPrice' => '150.00',
            'realizedPnlGross' => '150.00',
            'realizedPnlNet' => '140.00',
            'realizedPnlPct' => '0.10',
            'entryEventId' => 1,
            'exitEventId' => 2,
            'exitReason' => 'signal',
            'buySignalSnapshotId' => null,
            'sepaSnapshotId' => null,
            'epaSnapshotId' => null,
            'scoringVersion' => null,
            'policyVersion' => null,
            'modelVersion' => null,
            'macroVersion' => null,
            'seedSource' => 'live',
            'eligibilityStatus' => null,
            'exclusionReason' => null,
            'dataQualityFlags' => [],
        ], $overrides);

        return new EvidenceTradeSample(
            campaignId: $data['campaignId'],
            instrumentId: $data['instrumentId'],
            tradeType: $data['tradeType'],
            campaignState: $data['campaignState'],
            openedAt: $data['openedAt'],
            closedAt: $data['closedAt'],
            holdingDays: $data['holdingDays'],
            totalQuantity: $data['totalQuantity'],
            openQuantity: $data['openQuantity'],
            avgEntryPrice: $data['avgEntryPrice'],
            realizedPnlGross: $data['realizedPnlGross'],
            realizedPnlNet: $data['realizedPnlNet'],
            realizedPnlPct: $data['realizedPnlPct'],
            entryEventId: $data['entryEventId'],
            exitEventId: $data['exitEventId'],
            exitReason: $data['exitReason'],
            buySignalSnapshotId: $data['buySignalSnapshotId'],
            sepaSnapshotId: $data['sepaSnapshotId'],
            epaSnapshotId: $data['epaSnapshotId'],
            scoringVersion: $data['scoringVersion'],
            policyVersion: $data['policyVersion'],
            modelVersion: $data['modelVersion'],
            macroVersion: $data['macroVersion'],
            seedSource: $data['seedSource'],
            eligibilityStatus: $data['eligibilityStatus'],
            exclusionReason: $data['exclusionReason'],
            dataQualityFlags: $data['dataQualityFlags'],
        );
    }
}
