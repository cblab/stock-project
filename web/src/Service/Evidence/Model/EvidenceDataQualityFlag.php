<?php

declare(strict_types=1);

namespace App\Service\Evidence\Model;

/**
 * Defines data quality flags for evidence samples.
 *
 * Flags indicate quality concerns that do not necessarily exclude a sample,
 * but should be considered during aggregation and interpretation.
 * Multiple flags can be set on a single sample.
 */
final readonly class EvidenceDataQualityFlag
{
    private const MIGRATION_SEED = 'migration_seed';
    private const MANUAL_SEED = 'manual_seed';
    private const MISSING_ENTRY_SNAPSHOT = 'missing_entry_snapshot';
    private const MISSING_EXIT_SNAPSHOT = 'missing_exit_snapshot';
    private const SNAPSHOT_INCOMPLETE = 'snapshot_incomplete';
    private const CONTAINS_SEED_DATA = 'contains_seed_data';
    private const HIGH_VARIANCE = 'high_variance';
    private const LOW_SAMPLE_SIZE = 'low_sample_size';
    private const MIXED_PERIODS = 'mixed_periods';

    /** Sample originated from a migration seed entry */
    public static function migrationSeed(): self
    {
        return new self(self::MIGRATION_SEED);
    }

    /** Sample originated from a manual seed entry */
    public static function manualSeed(): self
    {
        return new self(self::MANUAL_SEED);
    }

    /** Entry snapshot is missing (may affect entry context) */
    public static function missingEntrySnapshot(): self
    {
        return new self(self::MISSING_ENTRY_SNAPSHOT);
    }

    /** Exit snapshot is missing (may affect exit context) */
    public static function missingExitSnapshot(): self
    {
        return new self(self::MISSING_EXIT_SNAPSHOT);
    }

    /** Snapshot data is incomplete (partial fields missing) */
    public static function snapshotIncomplete(): self
    {
        return new self(self::SNAPSHOT_INCOMPLETE);
    }

    /** Sample contains seed data (migration or manual) */
    public static function containsSeedData(): self
    {
        return new self(self::CONTAINS_SEED_DATA);
    }

    /** Sample shows high variance (statistical concern) */
    public static function highVariance(): self
    {
        return new self(self::HIGH_VARIANCE);
    }

    /** Sample comes from a low sample size context */
    public static function lowSampleSize(): self
    {
        return new self(self::LOW_SAMPLE_SIZE);
    }

    /** Sample spans mixed time periods (e.g., regime changes) */
    public static function mixedPeriods(): self
    {
        return new self(self::MIXED_PERIODS);
    }

    private function __construct(
        private string $value,
    ) {
    }

    /**
     * Get the string value of the data quality flag.
     */
    public function value(): string
    {
        return $this->value;
    }
}
