<?php

declare(strict_types=1);

namespace App\Service\Evidence\Model;

use DateTimeImmutable;

/**
 * Machine-readable readout of entry and exit evidence aggregation.
 */
final readonly class EvidenceReadout
{
    /**
     * @param EntryEvidenceBucketSummary[] $entryBuckets
     * @param ExitEvidenceBucketSummary[] $exitBuckets
     * @param string[] $warnings
     */
    public function __construct(
        public int $totalInputSamples,
        public int $entryBucketCount,
        public int $exitBucketCount,
        public int $globalSampleCount,
        public int $globalEligibleFullCount,
        public int $globalEligibleOutcomeOnlyCount,
        public int $globalExcludedCount,
        public array $entryBuckets,
        public array $exitBuckets,
        public array $warnings,
        public ?DateTimeImmutable $generatedAt = null,
    ) {
    }
}
