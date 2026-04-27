<?php

declare(strict_types=1);

namespace App\Service\Evidence\Model;

/**
 * Defines exclusion reasons for evidence samples.
 *
 * When eligibilityStatus is EXCLUDED, this reason explains why.
 * Used for audit trails, debugging, and quality reporting.
 */
final readonly class EvidenceExclusionReason
{
    private const OPEN_CAMPAIGN = 'open_campaign';
    private const INVALID_TIME_ORDER = 'invalid_time_order';
    private const MISSING_CLOSED_AT = 'missing_closed_at';
    private const MISSING_PNL = 'missing_pnl';
    private const MISSING_REQUIRED_SNAPSHOT = 'missing_required_snapshot';
    private const SNAPSHOT_AFTER_EVENT = 'snapshot_after_event';
    private const SNAPSHOT_INSTRUMENT_MISMATCH = 'snapshot_instrument_mismatch';
    private const MIGRATION_SEED_ENTRY_UNUSABLE = 'migration_seed_entry_unusable';
    private const MANUAL_SEED_WARNING = 'manual_seed_warning';
    private const UNKNOWN_STATE = 'unknown_state';
    private const UNSUPPORTED_TRADE_TYPE = 'unsupported_trade_type';
    private const MISSING_FORWARD_RETURN = 'missing_forward_return';
    private const MISSING_SCORE = 'missing_score';

    /** Campaign is still open; outcome not yet determined */
    public static function openCampaign(): self
    {
        return new self(self::OPEN_CAMPAIGN);
    }

    /** Event timestamps are out of order (e.g., exit before entry) */
    public static function invalidTimeOrder(): self
    {
        return new self(self::INVALID_TIME_ORDER);
    }

    /** Campaign is closed but closed_at timestamp is missing */
    public static function missingClosedAt(): self
    {
        return new self(self::MISSING_CLOSED_AT);
    }

    /** P&L fields are missing or incomplete */
    public static function missingPnl(): self
    {
        return new self(self::MISSING_PNL);
    }

    /** Required snapshot context is missing */
    public static function missingRequiredSnapshot(): self
    {
        return new self(self::MISSING_REQUIRED_SNAPSHOT);
    }

    /** Snapshot timestamp is after the event (hindsight violation) */
    public static function snapshotAfterEvent(): self
    {
        return new self(self::SNAPSHOT_AFTER_EVENT);
    }

    /** Snapshot instrument does not match trade instrument */
    public static function snapshotInstrumentMismatch(): self
    {
        return new self(self::SNAPSHOT_INSTRUMENT_MISMATCH);
    }

    /** Migration seed entry cannot be properly validated or used */
    public static function migrationSeedEntryUnusable(): self
    {
        return new self(self::MIGRATION_SEED_ENTRY_UNUSABLE);
    }

    /** Manual seed entry requires caution/warning */
    public static function manualSeedWarning(): self
    {
        return new self(self::MANUAL_SEED_WARNING);
    }

    /** Campaign or event state cannot be determined */
    public static function unknownState(): self
    {
        return new self(self::UNKNOWN_STATE);
    }

    /** Trade type is not supported for evidence aggregation */
    public static function unsupportedTradeType(): self
    {
        return new self(self::UNSUPPORTED_TRADE_TYPE);
    }

    /** Forward return data is missing for the required horizon */
    public static function missingForwardReturn(): self
    {
        return new self(self::MISSING_FORWARD_RETURN);
    }

    /** Score data is missing from the signal source */
    public static function missingScore(): self
    {
        return new self(self::MISSING_SCORE);
    }

    private function __construct(
        private string $value,
    ) {
    }

    /**
     * Get the string value of the exclusion reason.
     */
    public function value(): string
    {
        return $this->value;
    }
}
