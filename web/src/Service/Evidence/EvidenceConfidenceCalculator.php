<?php

declare(strict_types=1);

namespace App\Service\Evidence;

use App\Service\Evidence\Model\EvidenceConfidenceLevel;

/**
 * Central confidence calculator for evidence aggregations.
 *
 * Calculates qualitative confidence levels from sample count and optional
 * standard error. High uncertainty (SEM) can downgrade confidence.
 *
 * Ratio convention: standardError is passed as ratio (e.g., 0.05 = 5%)
 * not as percentage points.
 */
final readonly class EvidenceConfidenceCalculator
{
    /** Threshold for strong confidence (high) */
    private const STRONG_THRESHOLD = 50;

    /** Threshold for moderate confidence (medium) */
    private const MODERATE_THRESHOLD = 20;

    /** Threshold for weak confidence (low) */
    private const WEAK_THRESHOLD = 5;

    /** Threshold for anecdotal confidence */
    private const ANECDOTAL_THRESHOLD = 1;

    /** SEM threshold for capping confidence at weak (low) */
    private const SEM_CAP_WEAK = 0.10;

    /** SEM threshold for capping confidence at moderate (medium) */
    private const SEM_CAP_MODERATE = 0.05;

    /**
     * Calculate confidence level from sample count and optional standard error.
     *
     * @param int $sampleCount Number of samples (n)
     * @param float|null $standardError Standard error of mean as ratio (e.g., 0.05 = 5%)
     *
     * @return EvidenceConfidenceLevel The calculated confidence level
     */
    public function calculate(int $sampleCount, ?float $standardError = null): EvidenceConfidenceLevel
    {
        $baseConfidence = $this->calculateBaseConfidence($sampleCount);

        if ($standardError === null) {
            return $baseConfidence;
        }

        return $this->applySemAdjustment($baseConfidence, $standardError);
    }

    /**
     * Calculate base confidence from sample count only.
     *
     * Mapping:
     * - n <= 0         → anecdotal (lowest available level, no evidence)
     * - 1 <= n < 5     → anecdotal
     * - 5 <= n < 20    → low (weak)
     * - 20 <= n < 50   → medium (moderate)
     * - n >= 50        → high (strong)
     */
    private function calculateBaseConfidence(int $sampleCount): EvidenceConfidenceLevel
    {
        if ($sampleCount <= 0) {
            return EvidenceConfidenceLevel::anecdotal();
        }

        if ($sampleCount < self::WEAK_THRESHOLD) {
            return EvidenceConfidenceLevel::anecdotal();
        }

        if ($sampleCount < self::MODERATE_THRESHOLD) {
            return EvidenceConfidenceLevel::low();
        }

        if ($sampleCount < self::STRONG_THRESHOLD) {
            return EvidenceConfidenceLevel::medium();
        }

        return EvidenceConfidenceLevel::high();
    }

    /**
     * Apply SEM-based confidence downgrade.
     *
     * Rules:
     * - SEM > 0.10 → cap at low (weak) maximum
     * - SEM > 0.05 → cap at medium (moderate) maximum
     * - SEM <= 0.05 → no downgrade
     */
    private function applySemAdjustment(
        EvidenceConfidenceLevel $baseConfidence,
        float $standardError,
    ): EvidenceConfidenceLevel {
        // High SEM caps at low (weak)
        if ($standardError > self::SEM_CAP_WEAK) {
            return $this->capAtLow($baseConfidence);
        }

        // Medium SEM caps at medium (moderate)
        if ($standardError > self::SEM_CAP_MODERATE) {
            return $this->capAtMedium($baseConfidence);
        }

        // Low SEM: no adjustment needed
        return $baseConfidence;
    }

    /**
     * Cap confidence at low level.
     *
     * Returns the lower of base confidence and low.
     */
    private function capAtLow(EvidenceConfidenceLevel $base): EvidenceConfidenceLevel
    {
        if ($base->isHigh() || $base->isMedium()) {
            return EvidenceConfidenceLevel::low();
        }

        return $base;
    }

    /**
     * Cap confidence at medium level.
     *
     * Returns the lower of base confidence and medium.
     */
    private function capAtMedium(EvidenceConfidenceLevel $base): EvidenceConfidenceLevel
    {
        if ($base->isHigh()) {
            return EvidenceConfidenceLevel::medium();
        }

        return $base;
    }
}
