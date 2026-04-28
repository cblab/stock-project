<?php

declare(strict_types=1);

namespace App\Tests\Service\Evidence;

use App\Service\Evidence\EvidenceConfidenceCalculator;
use PHPUnit\Framework\TestCase;

/**
 * Tests for EvidenceConfidenceCalculator.
 *
 * Coverage:
 * - Base confidence thresholds by sample count
 * - SEM-based confidence downgrades
 * - Ratio semantics (values like 0.05 = 5%, not 5 percentage points)
 */
final class EvidenceConfidenceCalculatorTest extends TestCase
{
    private EvidenceConfidenceCalculator $calculator;

    protected function setUp(): void
    {
        $this->calculator = new EvidenceConfidenceCalculator();
    }

    // =================================================================
    // Base Confidence by Sample Count (Tests 1-8)
    // =================================================================

    /** Test 1: n=0 → insufficient (very_low) */
    public function testZeroSamplesReturnsVeryLow(): void
    {
        $result = $this->calculator->calculate(0);

        self::assertTrue($result->isVeryLow(), 'n=0 should return very_low (insufficient)');
    }

    /** Test 2: n=1 → anecdotal */
    public function testOneSampleReturnsAnecdotal(): void
    {
        $result = $this->calculator->calculate(1);

        self::assertTrue($result->isAnecdotal(), 'n=1 should return anecdotal');
    }

    /** Test 3: n=4 → anecdotal (boundary test) */
    public function testFourSamplesReturnsAnecdotal(): void
    {
        $result = $this->calculator->calculate(4);

        self::assertTrue($result->isAnecdotal(), 'n=4 should return anecdotal (just below weak threshold)');
    }

    /** Test 4: n=5 → weak (low) */
    public function testFiveSamplesReturnsLow(): void
    {
        $result = $this->calculator->calculate(5);

        self::assertTrue($result->isLow(), 'n=5 should return low (weak)');
    }

    /** Test 5: n=19 → weak (low) (boundary test) */
    public function testNineteenSamplesReturnsLow(): void
    {
        $result = $this->calculator->calculate(19);

        self::assertTrue($result->isLow(), 'n=19 should return low (weak, just below moderate threshold)');
    }

    /** Test 6: n=20 → moderate (medium) */
    public function testTwentySamplesReturnsMedium(): void
    {
        $result = $this->calculator->calculate(20);

        self::assertTrue($result->isMedium(), 'n=20 should return medium (moderate)');
    }

    /** Test 7: n=49 → moderate (medium) (boundary test) */
    public function testFortyNineSamplesReturnsMedium(): void
    {
        $result = $this->calculator->calculate(49);

        self::assertTrue($result->isMedium(), 'n=49 should return medium (moderate, just below strong threshold)');
    }

    /** Test 8: n=50 → strong (high) */
    public function testFiftySamplesReturnsHigh(): void
    {
        $result = $this->calculator->calculate(50);

        self::assertTrue($result->isHigh(), 'n=50 should return high (strong)');
    }

    // =================================================================
    // SEM-based Downgrade Tests (Tests 9-11)
    // Ratio semantics: 0.05 = 5%, 0.10 = 10% (not 0.0005 or 5.0)
    // =================================================================

    /**
     * Test 9: n=80 + SEM 0.12 → capped at weak (low)
     *
     * High SEM (> 0.10) caps confidence at low maximum.
     * Base would be high (n >= 50), but SEM downgrades to low.
     */
    public function testHighSemCapsAtLow(): void
    {
        $result = $this->calculator->calculate(80, 0.12);

        self::assertTrue(
            $result->isLow(),
            'n=80 with SEM=0.12 (12%) should be capped at low (weak) due to high uncertainty',
        );
    }

    /**
     * Test 10: n=80 + SEM 0.07 → capped at moderate (medium)
     *
     * Medium SEM (0.05 < SEM <= 0.10) caps confidence at medium maximum.
     * Base would be high (n >= 50), but SEM downgrades to medium.
     */
    public function testMediumSemCapsAtMedium(): void
    {
        $result = $this->calculator->calculate(80, 0.07);

        self::assertTrue(
            $result->isMedium(),
            'n=80 with SEM=0.07 (7%) should be capped at medium (moderate)',
        );
    }

    /**
     * Test 11: n=80 + SEM 0.03 → strong (high) (no downgrade)
     *
     * Low SEM (<= 0.05) does not downgrade confidence.
     * Base is high (n >= 50), SEM allows high to remain.
     */
    public function testLowSemAllowsHigh(): void
    {
        $result = $this->calculator->calculate(80, 0.03);

        self::assertTrue(
            $result->isHigh(),
            'n=80 with SEM=0.03 (3%) should remain high (strong) - no downgrade needed',
        );
    }

    // =================================================================
    // Additional Edge Cases
    // =================================================================

    /** Verify that null SEM does not affect base confidence */
    public function testNullSemPreservesBaseConfidence(): void
    {
        $result = $this->calculator->calculate(100, null);

        self::assertTrue($result->isHigh(), 'n=100 with null SEM should remain high');
    }

    /** Verify exact SEM threshold 0.05 does not downgrade */
    public function testSemExactlyAtThresholdDoesNotDowngrade(): void
    {
        $result = $this->calculator->calculate(60, 0.05);

        self::assertTrue(
            $result->isHigh(),
            'SEM=0.05 (exactly at threshold) should not trigger downgrade',
        );
    }

    /** Verify SEM just above 0.05 triggers moderate cap */
    public function testSemJustAboveThresholdCapsAtMedium(): void
    {
        $result = $this->calculator->calculate(60, 0.050001);

        self::assertTrue(
            $result->isMedium(),
            'SEM just above 0.05 should cap at medium',
        );
    }

    /** Verify SEM exactly 0.10 caps at low */
    public function testSemExactlyAtWeakThresholdCapsAtLow(): void
    {
        $result = $this->calculator->calculate(60, 0.10);

        // 0.10 is the threshold - values > 0.10 cap at low
        // At exactly 0.10, it falls into the medium cap (<= 0.10)
        self::assertTrue(
            $result->isMedium(),
            'SEM=0.10 (exactly at threshold) should cap at medium, not low',
        );
    }

    /** Verify SEM just above 0.10 caps at low */
    public function testSemJustAboveWeakThresholdCapsAtLow(): void
    {
        $result = $this->calculator->calculate(60, 0.100001);

        self::assertTrue(
            $result->isLow(),
            'SEM just above 0.10 should cap at low',
        );
    }

    /** Verify SEM adjustment respects lower base confidence (no upgrade) */
    public function testSemNeverUpgradesConfidence(): void
    {
        $lowBase = $this->calculator->calculate(10, 0.01);

        self::assertTrue(
            $lowBase->isLow(),
            'Low base confidence should not be upgraded by low SEM',
        );
    }

    /** Verify negative SEM is treated same as low SEM (no downgrade) */
    public function testNegativeSemTreatedAsNoDowngrade(): void
    {
        $result = $this->calculator->calculate(60, -0.01);

        self::assertTrue(
            $result->isHigh(),
            'Negative SEM should not downgrade from high base',
        );
    }
}