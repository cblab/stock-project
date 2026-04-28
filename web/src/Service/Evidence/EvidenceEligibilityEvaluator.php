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

        // At this point we expect terminal state - validate terminal-specific requirements

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

        // Rule 6: Unvalidated snapshot IDs
        if ($this->hasUnvalidatedSnapshots($sample)) {
            $flags[] = EvidenceDataQualityFlag::snapshotIncomplete();

            return EvidenceEligibilityResult::eligibleOutcomeOnly($flags);
        }

        // Rule 8: Default - fully eligible
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

    /**
     * Check if any snapshot IDs exist but are not validated.
     *
     * Note: This is a placeholder for future DB-level snapshot validation.
     * Currently assumes that if snapshot IDs are present but we don't have
     * validated context, the sample cannot be eligible_full.
     */
    private function hasUnvalidatedSnapshots(EvidenceTradeSample $sample): bool
    {
        // If any snapshot ID exists but we haven't validated it through
        // DB lookup (instrument match, timestamp check), mark as incomplete.
        // For now, presence of IDs without full validation = incomplete.
        $hasAnySnapshotId = $sample->buySignalSnapshotId !== null
            || $sample->sepaSnapshotId !== null
            || $sample->epaSnapshotId !== null;

        // TODO: Add DB-level validation in follow-up:
        // - Verify snapshot exists
        // - Verify snapshot instrument_id matches campaign
        // - Verify snapshot timestamp <= entry event timestamp

        return $hasAnySnapshotId;
    }
}
