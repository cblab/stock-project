<?php

declare(strict_types=1);

namespace App\Service\Evidence;

use App\Service\Evidence\Model\EvidenceDataQualityFlag;
use App\Service\Evidence\Model\EvidenceEligibilityResult;
use App\Service\Evidence\Model\EvidenceEligibilityStatus;
use App\Service\Evidence\Model\EvidenceExclusionReason;
use App\Service\Evidence\Model\EvidenceTradeSample;
use DateTimeImmutable;

/**
 * Central eligibility evaluator for evidence trade samples.
 *
 * Determines whether a sample is:
 * - eligible_full: Fully eligible for all aggregations
 * - eligible_outcome_only: Eligible for outcome aggregation only
 * - excluded: Excluded from all aggregations
 *
 * Rules are applied in order of severity:
 * 1. Terminal state check (non-terminal -> excluded)
 * 2. Time order validation (invalid -> excluded)
 * 3. Required field validation (missing closed_at/pnl -> excluded)
 * 4. Seed provenance (migration/manual -> outcome_only)
 * 5. Snapshot completeness (missing/unvalidated -> outcome_only)
 * 6. Default (eligible_full)
 */
final readonly class EvidenceEligibilityEvaluator
{
    private const TERMINAL_STATES = [
        'closed_profit',
        'closed_loss',
        'closed_neutral',
        'returned_to_watchlist',
    ];

    private const NON_TERMINAL_STATES = [
        'open',
        'trimmed',
        'paused',
    ];

    public function __construct(
        private ?SnapshotValidationService $snapshotValidationService = null,
    ) {
    }

    /**
     * Evaluate a trade sample for eligibility.
     *
     * Returns an EvidenceEligibilityResult containing:
     * - status: The final eligibility status
     * - exclusionReason: The reason for exclusion (if excluded)
     * - dataQualityFlags: Any quality flags that apply
     */
    public function evaluateTradeSample(EvidenceTradeSample $sample): EvidenceEligibilityResult
    {
        $flags = [];

        // Rule 1: Terminal state check
        if ($this->isNonTerminalState($sample->campaignState)) {
            return EvidenceEligibilityResult::excluded(
                EvidenceExclusionReason::openCampaign(),
                $flags,
            );
        }

        // Rule 1b: Unknown state check - only allow explicitly known terminal states
        if (!$this->isTerminalState($sample->campaignState)) {
            return EvidenceEligibilityResult::excluded(
                EvidenceExclusionReason::unknownState(),
                $flags,
            );
        }

        // At this point we have a valid terminal state - validate terminal-specific requirements

        // Rule 2: Time order validation
        if ($this->hasInvalidTimeOrder($sample)) {
            return EvidenceEligibilityResult::excluded(
                EvidenceExclusionReason::invalidTimeOrder(),
                $flags,
            );
        }

        // Rule 3a: Missing closed_at for terminal campaign
        if ($sample->closedAt === null) {
            return EvidenceEligibilityResult::excluded(
                EvidenceExclusionReason::missingClosedAt(),
                $flags,
            );
        }

        // Rule 3b: Missing PnL for terminal campaign
        if ($sample->realizedPnlPct === null) {
            return EvidenceEligibilityResult::excluded(
                EvidenceExclusionReason::missingPnl(),
                $flags,
            );
        }

        // Rule 4: Seed provenance (migration seed)
        if ($sample->seedSource === 'migration') {
            $flags[] = EvidenceDataQualityFlag::migrationSeed();
            $flags[] = EvidenceDataQualityFlag::containsSeedData();

            return EvidenceEligibilityResult::eligibleOutcomeOnly($flags);
        }

        // Rule 4b: Seed provenance (manual seed)
        if ($sample->seedSource === 'manual') {
            $flags[] = EvidenceDataQualityFlag::manualSeed();
            $flags[] = EvidenceDataQualityFlag::containsSeedData();

            return EvidenceEligibilityResult::eligibleOutcomeOnly($flags);
        }

        // Rule 5: Snapshot completeness
        if ($this->hasMissingSnapshots($sample)) {
            $flags[] = EvidenceDataQualityFlag::missingEntrySnapshot();
            $flags[] = EvidenceDataQualityFlag::snapshotIncomplete();

            return EvidenceEligibilityResult::eligibleOutcomeOnly($flags);
        }

        // Rule 6: Snapshot validation failed
        if ($this->hasInvalidSnapshots($sample)) {
            $flags[] = EvidenceDataQualityFlag::snapshotIncomplete();

            return EvidenceEligibilityResult::eligibleOutcomeOnly($flags);
        }

        // Rule 7: Default - fully eligible
        return EvidenceEligibilityResult::eligibleFull($flags);
    }

    /**
     * Check if the campaign state is non-terminal (open, trimmed, paused).
     */
    private function isNonTerminalState(string $state): bool
    {
        return in_array($state, self::NON_TERMINAL_STATES, true);
    }

    /**
     * Check if the campaign state is a valid terminal state.
     */
    private function isTerminalState(string $state): bool
    {
        return in_array($state, self::TERMINAL_STATES, true);
    }

    /**
     * Check for invalid time order (closed before opened).
     */
    private function hasInvalidTimeOrder(EvidenceTradeSample $sample): bool
    {
        if ($sample->closedAt === null) {
            return false;
        }

        return $sample->closedAt < $sample->openedAt;
    }

    /**
     * Check if entry snapshots are completely missing.
     */
    private function hasMissingSnapshots(EvidenceTradeSample $sample): bool
    {
        return $sample->buySignalSnapshotId === null
            && $sample->sepaSnapshotId === null
            && $sample->epaSnapshotId === null;
    }

    private function hasInvalidSnapshots(EvidenceTradeSample $sample): bool
    {
        // Stay conservative when the validator is not wired:
        // any present snapshot IDs cannot produce eligible_full.
        if ($this->snapshotValidationService === null) {
            return $sample->buySignalSnapshotId !== null
                || $sample->sepaSnapshotId !== null
                || $sample->epaSnapshotId !== null;
        }

        $entryTimestamp = $sample->openedAt;
        $expectedInstrumentId = $sample->instrumentId;

        if ($sample->buySignalSnapshotId !== null) {
            $result = $this->snapshotValidationService->validateBuySignalSnapshot(
                $sample->buySignalSnapshotId,
                $expectedInstrumentId,
                $entryTimestamp,
            );

            if (!$result->isValid()) {
                return true;
            }
        }

        if ($sample->sepaSnapshotId !== null) {
            $result = $this->snapshotValidationService->validateSepaSnapshot(
                $sample->sepaSnapshotId,
                $expectedInstrumentId,
                $entryTimestamp,
            );

            if (!$result->isValid()) {
                return true;
            }
        }

        if ($sample->epaSnapshotId !== null) {
            $result = $this->snapshotValidationService->validateEpaSnapshot(
                $sample->epaSnapshotId,
                $expectedInstrumentId,
                $entryTimestamp,
            );

            if (!$result->isValid()) {
                return true;
            }
        }

        return false;
    }
}
