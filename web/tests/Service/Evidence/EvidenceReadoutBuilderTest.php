<?php

declare(strict_types=1);

namespace App\Tests\Service\Evidence;

use App\Service\Evidence\EntryEvidenceAggregator;
use App\Service\Evidence\EvidenceConfidenceCalculator;
use App\Service\Evidence\EvidenceEligibilityEvaluator;
use App\Service\Evidence\EvidenceReadoutBuilder;
use App\Service\Evidence\ExitEvidenceAggregator;
use App\Tests\Service\Evidence\Fixture\EvidenceTradeSampleFixture;
use PHPUnit\Framework\TestCase;

final class EvidenceReadoutBuilderTest extends TestCase
{
    private EvidenceReadoutBuilder $builder;

    protected function setUp(): void
    {
        $evaluator = new EvidenceEligibilityEvaluator();
        $confidenceCalculator = new EvidenceConfidenceCalculator();

        $this->builder = new EvidenceReadoutBuilder(
            new EntryEvidenceAggregator($evaluator, $confidenceCalculator),
            new ExitEvidenceAggregator($evaluator, $confidenceCalculator),
        );
    }

    public function testBuildReturnsEntryAndExitBuckets(): void
    {
        $samples = [
            EvidenceTradeSampleFixture::closedProfit(),
            EvidenceTradeSampleFixture::closedLoss(),
        ];

        $readout = $this->builder->build($samples);

        self::assertGreaterThan(0, $readout->entryBucketCount);
        self::assertGreaterThan(0, $readout->exitBucketCount);
        self::assertNotEmpty($readout->entryBuckets);
        self::assertNotEmpty($readout->exitBuckets);
    }

    public function testGlobalCountsComeFromEntryBucketsOnly(): void
    {
        $samples = [
            EvidenceTradeSampleFixture::closedProfit(),
            EvidenceTradeSampleFixture::openCampaign(),
        ];

        $readout = $this->builder->build($samples);

        self::assertSame(1, $readout->globalSampleCount);
        self::assertSame(0, $readout->globalEligibleFullCount);
        self::assertSame(1, $readout->globalEligibleOutcomeOnlyCount);
        self::assertSame(1, $readout->globalExcludedCount);
    }

    public function testNoEligibleSamplesWarning(): void
    {
        $readout = $this->builder->build([
            EvidenceTradeSampleFixture::openCampaign(),
            EvidenceTradeSampleFixture::openCampaign(),
        ]);

        self::assertSame(0, $readout->globalSampleCount);
        self::assertContains('no_eligible_samples', $readout->warnings);
    }

    public function testContainsOutcomeOnlyWarning(): void
    {
        $readout = $this->builder->build([
            EvidenceTradeSampleFixture::closedProfit(),
        ]);

        self::assertContains('contains_outcome_only_samples', $readout->warnings);
    }

    public function testNoFullEntryEvidenceWarning(): void
    {
        $readout = $this->builder->build([
            EvidenceTradeSampleFixture::closedProfit(),
        ]);

        self::assertSame(0, $readout->globalEligibleFullCount);
        self::assertSame(1, $readout->globalSampleCount);
        self::assertContains('no_full_entry_evidence', $readout->warnings);
    }

    public function testContainsExcludedSamplesWarning(): void
    {
        $readout = $this->builder->build([
            EvidenceTradeSampleFixture::closedProfit(),
            EvidenceTradeSampleFixture::openCampaign(),
        ]);

        self::assertContains('contains_excluded_samples', $readout->warnings);
    }

    public function testLowConfidenceWarning(): void
    {
        $readout = $this->builder->build([
            EvidenceTradeSampleFixture::closedProfit(),
            EvidenceTradeSampleFixture::closedLoss(),
            EvidenceTradeSampleFixture::closedNeutral(),
        ]);

        self::assertContains('low_confidence_evidence', $readout->warnings);
    }

    public function testEmptyInput(): void
    {
        $readout = $this->builder->build([]);

        self::assertSame(0, $readout->totalInputSamples);
        self::assertSame(0, $readout->entryBucketCount);
        self::assertSame(0, $readout->exitBucketCount);
        self::assertSame(0, $readout->globalSampleCount);
        self::assertContains('no_eligible_samples', $readout->warnings);
    }
}
