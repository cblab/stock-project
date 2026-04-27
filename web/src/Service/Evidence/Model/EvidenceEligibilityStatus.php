<?php

declare(strict_types=1);

namespace App\Service\Evidence\Model;

/**
 * Defines eligibility statuses for evidence samples.
 *
 * Eligibility determines how a sample may be used in aggregation.
 * Anti-hindsight rules and data quality checks feed into eligibility decisions.
 */
final readonly class EvidenceEligibilityStatus
{
    private const ELIGIBLE_FULL = 'eligible_full';
    private const ELIGIBLE_OUTCOME_ONLY = 'eligible_outcome_only';
    private const ELIGIBLE_SNAPSHOT_ONLY = 'eligible_snapshot_only';
    private const EXCLUDED = 'excluded';

    /**
     * Sample is fully eligible for all aggregations.
     * Both trade outcome and snapshot context are valid.
     */
    public static function eligibleFull(): self
    {
        return new self(self::ELIGIBLE_FULL);
    }

    /**
     * Sample is eligible for outcome aggregation only.
     * Trade result is valid but snapshot context is missing or invalid.
     */
    public static function eligibleOutcomeOnly(): self
    {
        return new self(self::ELIGIBLE_OUTCOME_ONLY);
    }

    /**
     * Sample is eligible for snapshot aggregation only.
     * Snapshot context is valid but trade outcome is not applicable.
     */
    public static function eligibleSnapshotOnly(): self
    {
        return new self(self::ELIGIBLE_SNAPSHOT_ONLY);
    }

    /**
     * Sample is excluded from all aggregations.
     * See exclusion reason for details.
     */
    public static function excluded(): self
    {
        return new self(self::EXCLUDED);
    }

    /**
     * Create from string value.
     *
     * @throws \InvalidArgumentException if value is invalid
     */
    public static function fromString(string $value): self
    {
        return match ($value) {
            self::ELIGIBLE_FULL => new self(self::ELIGIBLE_FULL),
            self::ELIGIBLE_OUTCOME_ONLY => new self(self::ELIGIBLE_OUTCOME_ONLY),
            self::ELIGIBLE_SNAPSHOT_ONLY => new self(self::ELIGIBLE_SNAPSHOT_ONLY),
            self::EXCLUDED => new self(self::EXCLUDED),
            default => throw new \InvalidArgumentException("Invalid EvidenceEligibilityStatus: {$value}"),
        };
    }

    /**
     * Try to create from string value.
     * Returns null if value is invalid.
     */
    public static function tryFromString(string $value): ?self
    {
        try {
            return self::fromString($value);
        } catch (\InvalidArgumentException) {
            return null;
        }
    }

    private function __construct(
        private string $value,
    ) {
    }

    /**
     * Get the string value of the status.
     */
    public function value(): string
    {
        return $this->value;
    }

    /**
     * Check if the sample is eligible (any level).
     */
    public function isEligible(): bool
    {
        return $this->value !== self::EXCLUDED;
    }

    /**
     * Check if the sample is fully eligible.
     */
    public function isEligibleFull(): bool
    {
        return $this->value === self::ELIGIBLE_FULL;
    }

    /**
     * Check if the sample is excluded.
     */
    public function isExcluded(): bool
    {
        return $this->value === self::EXCLUDED;
    }
}
