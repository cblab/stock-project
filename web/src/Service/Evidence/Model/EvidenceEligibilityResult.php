<?php

declare(strict_types=1);

namespace App\Service\Evidence\Model;

/**
 * Immutable DTO representing the result of an eligibility evaluation.
 *
 * Contains the final eligibility status, optional exclusion reason,
 * and any data quality flags that apply to the sample.
 */
final readonly class EvidenceEligibilityResult
{
    /**
     * @param EvidenceEligibilityStatus $status The final eligibility status
     * @param EvidenceExclusionReason|null $exclusionReason Reason for exclusion (if excluded)
     * @param EvidenceDataQualityFlag[] $dataQualityFlags Quality flags for this sample
     */
    public function __construct(
        public EvidenceEligibilityStatus $status,
        public ?EvidenceExclusionReason $exclusionReason,
        public array $dataQualityFlags = [],
    ) {
    }

    /**
     * Factory method for creating an eligible_full result.
     *
     * @param EvidenceDataQualityFlag[] $flags
     */
    public static function eligibleFull(array $flags = []): self
    {
        return new self(
            EvidenceEligibilityStatus::eligibleFull(),
            null,
            $flags,
        );
    }

    /**
     * Factory method for creating an eligible_outcome_only result.
     *
     * @param EvidenceDataQualityFlag[] $flags
     */
    public static function eligibleOutcomeOnly(array $flags = []): self
    {
        return new self(
            EvidenceEligibilityStatus::eligibleOutcomeOnly(),
            null,
            $flags,
        );
    }

    /**
     * Factory method for creating an excluded result.
     */
    public static function excluded(EvidenceExclusionReason $reason, array $flags = []): self
    {
        return new self(
            EvidenceEligibilityStatus::excluded(),
            $reason,
            $flags,
        );
    }

    /**
     * Check if the sample is eligible (any level).
     */
    public function isEligible(): bool
    {
        return $this->status->isEligible();
    }

    /**
     * Check if the sample is fully eligible.
     */
    public function isEligibleFull(): bool
    {
        return $this->status->isEligibleFull();
    }

    /**
     * Check if the sample is excluded.
     */
    public function isExcluded(): bool
    {
        return $this->status->isExcluded();
    }
}
