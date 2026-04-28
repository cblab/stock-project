<?php

declare(strict_types=1);

namespace App\Service\Evidence;

use App\Service\Evidence\Model\EvidenceConfidenceLevel;
use App\Service\Evidence\Model\EvidenceReadout;
use App\Service\Evidence\Model\EvidenceTradeSample;

/**
 * Builds a neutral, machine-readable evidence readout.
 *
 * C7 Scope:
 * - combines entry and exit bucket summaries
 * - derives global composition counts from entry buckets only
 * - emits stable guardrail warning codes
 * - does not add recommendations, scoring, or ranking
 */
final readonly class EvidenceReadoutBuilder
{
    public function __construct(
        private EntryEvidenceAggregator $entryEvidenceAggregator,
        private ExitEvidenceAggregator $exitEvidenceAggregator,
    ) {
    }

    /**
     * @param EvidenceTradeSample[] $samples
     */
    public function build(array $samples): EvidenceReadout
    {
        $entryBuckets = $this->entryEvidenceAggregator->aggregateByEntryBucket($samples);
        $exitBuckets = $this->exitEvidenceAggregator->aggregateByExitBucket($samples);

        $globalSampleCount = 0;
        $globalEligibleFullCount = 0;
        $globalEligibleOutcomeOnlyCount = 0;
        $globalExcludedCount = 0;

        foreach ($entryBuckets as $bucket) {
            $globalSampleCount += $bucket->sampleCount;
            $globalEligibleFullCount += $bucket->eligibleFullCount;
            $globalEligibleOutcomeOnlyCount += $bucket->eligibleOutcomeOnlyCount;
            $globalExcludedCount += $bucket->excludedCount;
        }

        return new EvidenceReadout(
            totalInputSamples: count($samples),
            entryBucketCount: count($entryBuckets),
            exitBucketCount: count($exitBuckets),
            globalSampleCount: $globalSampleCount,
            globalEligibleFullCount: $globalEligibleFullCount,
            globalEligibleOutcomeOnlyCount: $globalEligibleOutcomeOnlyCount,
            globalExcludedCount: $globalExcludedCount,
            entryBuckets: $entryBuckets,
            exitBuckets: $exitBuckets,
            warnings: $this->buildWarnings(
                $globalSampleCount,
                $globalEligibleFullCount,
                $globalEligibleOutcomeOnlyCount,
                $globalExcludedCount,
                $entryBuckets,
                $exitBuckets,
            ),
            generatedAt: null,
        );
    }

    /**
     * @param array<int, object{confidenceLevel: EvidenceConfidenceLevel}> $entryBuckets
     * @param array<int, object{confidenceLevel: EvidenceConfidenceLevel}> $exitBuckets
     * @return string[]
     */
    private function buildWarnings(
        int $globalSampleCount,
        int $globalEligibleFullCount,
        int $globalEligibleOutcomeOnlyCount,
        int $globalExcludedCount,
        array $entryBuckets,
        array $exitBuckets,
    ): array {
        $warnings = [];

        if ($globalSampleCount === 0) {
            $warnings[] = 'no_eligible_samples';
        }

        if ($globalEligibleOutcomeOnlyCount > 0) {
            $warnings[] = 'contains_outcome_only_samples';
        }

        if ($globalEligibleFullCount === 0 && $globalSampleCount > 0) {
            $warnings[] = 'no_full_entry_evidence';
        }

        if ($globalExcludedCount > 0) {
            $warnings[] = 'contains_excluded_samples';
        }

        foreach (array_merge($entryBuckets, $exitBuckets) as $bucket) {
            if ($this->isLowConfidence($bucket->confidenceLevel)) {
                $warnings[] = 'low_confidence_evidence';
                break;
            }
        }

        return array_values(array_unique($warnings));
    }

    private function isLowConfidence(EvidenceConfidenceLevel $confidenceLevel): bool
    {
        return $confidenceLevel->isAnecdotal()
            || $confidenceLevel->isVeryLow()
            || $confidenceLevel->isLow();
    }
}
