<?php

declare(strict_types=1);

namespace App\Service\Evidence\Model;

use DateTimeImmutable;

/**
 * Immutable DTO representing a single signal forward-return evidence sample.
 *
 * Captures a signal state with its subsequent forward return for evidence aggregation.
 * Signal-source-agnostic: works with SEPA, EPA, Buy-Signal, Kronos, Sentiment, or custom sources.
 *
 * Ratio convention: forwardReturnPct stored as ratio
 * Example: 0.30 = +30%, -0.12 = -12%
 *
 * Anti-Hindsight Rule:
 * A signal is only valid evidence when:
 * 1. Instrument is known (instrumentId)
 * 2. Signal timestamp is known (asOfAt)
 * 3. Source/version is traceable (signalSource, signalVersion)
 * 4. Outcome horizon is defined (horizonDays)
 */
final readonly class EvidenceSignalSample
{
    /**
     * @param SignalSource $signalSource Specific signal source (sepa, epa, buy_signal, kronos, sentiment, custom)
     * @param SignalFamily|null $signalFamily Signal family category (structure, execution, risk, sentiment, composite, unknown)
     * @param string|null $sourceTable Source database table name (e.g., instrument_sepa_snapshot)
     * @param int|string $sourceId ID or stable key of the origin signal
     * @param int $instrumentId Instrument ID
     * @param DateTimeImmutable $asOfAt Timestamp when the signal was known
     * @param int|null $horizonDays Forward return horizon in days (5, 20, 60, etc.)
     * @param string|null $forwardReturnPct Forward return as ratio (e.g., "0.30" = +30%, "-0.12" = -12%)
     * @param string|null $score Main score from the signal source
     * @param string|null $scoreBucket Score bucket for aggregation (e.g., "0-39", "40-59", "60-74", "75-84", "85+")
     * @param string|null $signalVersion Signal source version or calculation logic version
     * @param string|null $detailRef Optional reference to detail data (JSON key, run_id, snapshot_id, etc.)
     * @param EvidenceEligibilityStatus|null $eligibilityStatus Eligibility for aggregation
     * @param EvidenceExclusionReason|null $exclusionReason Reason for exclusion (if excluded)
     * @param EvidenceDataQualityFlag[] $dataQualityFlags Quality flags for this sample
     */
    public function __construct(
        public SignalSource $signalSource,
        public ?SignalFamily $signalFamily,
        public ?string $sourceTable,
        public int|string $sourceId,
        public int $instrumentId,
        public DateTimeImmutable $asOfAt,
        public ?int $horizonDays,
        public ?string $forwardReturnPct,
        public ?string $score,
        public ?string $scoreBucket,
        public ?string $signalVersion,
        public ?string $detailRef,
        public ?EvidenceEligibilityStatus $eligibilityStatus,
        public ?EvidenceExclusionReason $exclusionReason,
        public array $dataQualityFlags = [],
    ) {
    }
}
