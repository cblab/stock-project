<?php

declare(strict_types=1);

namespace App\Service\Evidence;

use App\Service\Evidence\Model\EvidenceDataQualityFlag;
use App\Service\Evidence\Model\EvidenceEligibilityStatus;
use App\Service\Evidence\Model\EvidenceExclusionReason;
use App\Service\Evidence\Model\EvidenceTradeSample;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;

final readonly class TradeOutcomeExtractor
{
    private const TERMINAL_STATES = ['closed_profit', 'closed_loss', 'closed_neutral', 'returned_to_watchlist'];

    public function __construct(private Connection $connection) {}

    public function extractClosedSamples(?string $tradeType = null): array
    {
        $campaigns = $this->fetchTerminalCampaigns($tradeType);
        $samples = [];
        foreach ($campaigns as $campaign) {
            $sample = $this->mapCampaignToSample($campaign);
            if ($sample !== null) $samples[] = $sample;
        }
        return $samples;
    }

    private function fetchTerminalCampaigns(?string $tradeType): array
    {
        $placeholders = implode(',', array_fill(0, count(self::TERMINAL_STATES), '?'));
        $sql = "SELECT * FROM trade_campaign WHERE state IN ($placeholders)";
        $params = self::TERMINAL_STATES;
        if ($tradeType !== null) {
            $sql .= ' AND trade_type = ?';
            $params[] = $tradeType;
        }
        $sql .= ' ORDER BY closed_at ASC, id ASC';
        return $this->connection->fetchAllAssociative($sql, $params);
    }

    private function mapCampaignToSample(array $campaign): ?EvidenceTradeSample
    {
        $campaignId = (int) $campaign['id'];
        $instrumentId = (int) $campaign['instrument_id'];
        $state = (string) $campaign['state'];
        if (!in_array($state, self::TERMINAL_STATES, true)) return null;
        $openedAt = $this->parseDateTime($campaign['opened_at']);
        if ($openedAt === null) {
            return null; // Skip samples with unparseable opened_at
        }
        $entryEvent = $this->fetchEntryEvent($campaignId);
        $exitEvent = $this->fetchExitEvent($campaignId, $state);
        $seedSource = $this->determineSeedSource($campaignId, $entryEvent);
        $eligibilityResult = $this->determineEligibility($campaign, $entryEvent, $exitEvent, $seedSource);
        $holdingDays = $this->calculateHoldingDays($campaign);
        return new EvidenceTradeSample(
            campaignId: $campaignId,
            instrumentId: $instrumentId,
            tradeType: (string) $campaign['trade_type'],
            campaignState: $state,
            openedAt: $openedAt,
            closedAt: $this->parseDateTime($campaign['closed_at']),
            holdingDays: $holdingDays,
            totalQuantity: (string) $campaign['total_quantity'],
            openQuantity: $campaign['open_quantity'] !== null ? (string) $campaign['open_quantity'] : null,
            avgEntryPrice: $campaign['avg_entry_price'] !== null ? (string) $campaign['avg_entry_price'] : null,
            realizedPnlGross: $campaign['realized_pnl_gross'] !== null ? (string) $campaign['realized_pnl_gross'] : null,
            realizedPnlNet: $campaign['realized_pnl_net'] !== null ? (string) $campaign['realized_pnl_net'] : null,
            realizedPnlPct: $campaign['realized_pnl_pct'] !== null ? (string) $campaign['realized_pnl_pct'] : null,
            entryEventId: $entryEvent !== null ? (int) $entryEvent['id'] : null,
            exitEventId: $exitEvent !== null ? (int) $exitEvent['id'] : null,
            exitReason: $exitEvent !== null ? ($exitEvent['exit_reason'] ?? null) : null,
            buySignalSnapshotId: $entryEvent !== null ? ($entryEvent['buy_signal_snapshot_id'] ?? null) : null,
            sepaSnapshotId: $entryEvent !== null ? ($entryEvent['sepa_snapshot_id'] ?? null) : null,
            epaSnapshotId: $entryEvent !== null ? ($entryEvent['epa_snapshot_id'] ?? null) : null,
            scoringVersion: $entryEvent !== null ? ($entryEvent['scoring_version'] ?? null) : null,
            policyVersion: $entryEvent !== null ? ($entryEvent['policy_version'] ?? null) : null,
            modelVersion: $entryEvent !== null ? ($entryEvent['model_version'] ?? null) : null,
            macroVersion: $entryEvent !== null ? ($entryEvent['macro_version'] ?? null) : null,
            seedSource: $seedSource,
            eligibilityStatus: $eligibilityResult['status'],
            exclusionReason: $eligibilityResult['reason'],
            dataQualityFlags: $eligibilityResult['flags'],
        );
    }

    private function fetchEntryEvent(int $campaignId): ?array
    {
        $sql = "SELECT * FROM trade_event WHERE trade_campaign_id = ? AND event_type IN ('entry', 'migration_seed') ORDER BY event_timestamp ASC, id ASC LIMIT 1";
        $result = $this->connection->fetchAssociative($sql, [$campaignId]);
        return $result ?: null;
    }

    private function fetchExitEvent(int $campaignId, string $state): ?array
    {
        if ($state === 'returned_to_watchlist') {
            $sql = "SELECT * FROM trade_event WHERE trade_campaign_id = ? AND event_type = 'return_to_watchlist' ORDER BY event_timestamp DESC, id DESC LIMIT 1";
        } else {
            $sql = "SELECT * FROM trade_event WHERE trade_campaign_id = ? AND event_type = 'hard_exit' ORDER BY event_timestamp DESC, id DESC LIMIT 1";
        }
        $result = $this->connection->fetchAssociative($sql, [$campaignId]);
        return $result ?: null;
    }

    private function determineSeedSource(int $campaignId, ?array $entryEvent): ?string
    {
        if ($entryEvent !== null && $entryEvent['event_type'] === 'migration_seed') {
            $migrationStatus = $this->connection->fetchOne("SELECT migration_status FROM trade_migration_log WHERE trade_campaign_id = ?", [$campaignId]);
            return $migrationStatus === 'manual_seed' ? 'manual' : 'migration';
        }
        if ($entryEvent !== null && $entryEvent['event_type'] === 'entry') return 'live';
        return null;
    }

    private function determineEligibility(array $campaign, ?array $entryEvent, ?array $exitEvent, ?string $seedSource): array
    {
        $flags = [];
        $openedAt = $this->parseDateTime($campaign['opened_at']);
        $closedAt = $this->parseDateTime($campaign['closed_at']);
        $realizedPnlPct = $campaign['realized_pnl_pct'];
        if ($openedAt !== null && $closedAt !== null && $closedAt < $openedAt) {
            return ['status' => EvidenceEligibilityStatus::excluded(), 'reason' => EvidenceExclusionReason::invalidTimeOrder(), 'flags' => $flags];
        }
        if ($closedAt === null) {
            return ['status' => EvidenceEligibilityStatus::excluded(), 'reason' => EvidenceExclusionReason::missingClosedAt(), 'flags' => $flags];
        }
        if ($realizedPnlPct === null) {
            return ['status' => EvidenceEligibilityStatus::excluded(), 'reason' => EvidenceExclusionReason::missingPnl(), 'flags' => $flags];
        }
        if ($seedSource === 'migration') {
            $flags[] = EvidenceDataQualityFlag::migrationSeed();
            $flags[] = EvidenceDataQualityFlag::containsSeedData();
            return ['status' => EvidenceEligibilityStatus::eligibleOutcomeOnly(), 'reason' => null, 'flags' => $flags];
        }
        if ($seedSource === 'manual') {
            $flags[] = EvidenceDataQualityFlag::manualSeed();
            $flags[] = EvidenceDataQualityFlag::containsSeedData();
            return ['status' => EvidenceEligibilityStatus::eligibleOutcomeOnly(), 'reason' => null, 'flags' => $flags];
        }
        if ($this->entryEventHasNoSnapshots($entryEvent)) {
            $flags[] = EvidenceDataQualityFlag::missingEntrySnapshot();
            $flags[] = EvidenceDataQualityFlag::snapshotIncomplete();
            return ['status' => EvidenceEligibilityStatus::eligibleOutcomeOnly(), 'reason' => null, 'flags' => $flags];
        }
        if ($this->entryEventHasAnySnapshots($entryEvent)) {
            $flags[] = EvidenceDataQualityFlag::snapshotIncomplete();
            return ['status' => EvidenceEligibilityStatus::eligibleOutcomeOnly(), 'reason' => null, 'flags' => $flags];
        }
        if ($exitEvent === null) {
            $flags[] = EvidenceDataQualityFlag::snapshotIncomplete();
            return ['status' => EvidenceEligibilityStatus::eligibleOutcomeOnly(), 'reason' => null, 'flags' => $flags];
        }
        if ($entryEvent === null) {
            $flags[] = EvidenceDataQualityFlag::missingEntrySnapshot();
            $flags[] = EvidenceDataQualityFlag::snapshotIncomplete();
            return ['status' => EvidenceEligibilityStatus::eligibleOutcomeOnly(), 'reason' => null, 'flags' => $flags];
        }
        return ['status' => EvidenceEligibilityStatus::eligibleFull(), 'reason' => null, 'flags' => $flags];
    }

    private function calculateHoldingDays(array $campaign): ?int
    {
        $openedAt = $this->parseDateTime($campaign['opened_at']);
        $closedAt = $this->parseDateTime($campaign['closed_at']);
        if ($openedAt === null || $closedAt === null) return null;
        return $closedAt->diff($openedAt)->days;
    }

    private function parseDateTime(?string $value): ?DateTimeImmutable
    {
        if ($value === null) return null;
        try {
            return new DateTimeImmutable($value);
        } catch (\Exception $e) {
            return null;
        }
    }

    private function entryEventHasNoSnapshots(?array $entryEvent): bool
    {
        if ($entryEvent === null) return true;
        return ($entryEvent['buy_signal_snapshot_id'] ?? null) === null
            && ($entryEvent['sepa_snapshot_id'] ?? null) === null
            && ($entryEvent['epa_snapshot_id'] ?? null) === null;
    }

    private function entryEventHasAnySnapshots(?array $entryEvent): bool
    {
        if ($entryEvent === null) return false;
        return ($entryEvent['buy_signal_snapshot_id'] ?? null) !== null
            || ($entryEvent['sepa_snapshot_id'] ?? null) !== null
            || ($entryEvent['epa_snapshot_id'] ?? null) !== null;
    }
}
