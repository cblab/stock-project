<?php

declare(strict_types=1);

namespace App\Service\Evidence;

use App\Service\Evidence\Model\EvidenceEligibilityResult;
use App\Service\Evidence\Model\EvidenceTradeSample;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;

final readonly class TradeOutcomeExtractor
{
    private const TERMINAL_STATES = ['closed_profit', 'closed_loss', 'closed_neutral', 'returned_to_watchlist'];

    private EvidenceEligibilityEvaluator $eligibilityEvaluator;

    public function __construct(private Connection $connection)
    {
        $this->eligibilityEvaluator = new EvidenceEligibilityEvaluator();
    }

    public function extractClosedSamples(?string $tradeType = null): array
    {
        $campaigns = $this->fetchTerminalCampaigns($tradeType);
        $samples = [];
        foreach ($campaigns as $campaign) {
            $sample = $this->mapCampaignToSample($campaign);
            if ($sample !== null) {
                $samples[] = $sample;
            }
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

        if (!in_array($state, self::TERMINAL_STATES, true)) {
            return null;
        }

        $openedAt = $this->parseDateTime($campaign['opened_at']);
        if ($openedAt === null) {
            return null;
        }

        $entryEvent = $this->fetchEntryEvent($campaignId);
        $exitEvent = $this->fetchExitEvent($campaignId, $state);
        $seedSource = $this->determineSeedSource($campaignId, $entryEvent);
        $holdingDays = $this->calculateHoldingDays($campaign);

        $rawSample = new EvidenceTradeSample(
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
            eligibilityStatus: null,
            exclusionReason: null,
            dataQualityFlags: [],
        );

        $eligibilityResult = $this->eligibilityEvaluator->evaluateTradeSample($rawSample);

        return $this->applyEligibilityResult($rawSample, $eligibilityResult);
    }

    private function applyEligibilityResult(EvidenceTradeSample $rawSample, EvidenceEligibilityResult $result): EvidenceTradeSample
    {
        return new EvidenceTradeSample(
            campaignId: $rawSample->campaignId,
            instrumentId: $rawSample->instrumentId,
            tradeType: $rawSample->tradeType,
            campaignState: $rawSample->campaignState,
            openedAt: $rawSample->openedAt,
            closedAt: $rawSample->closedAt,
            holdingDays: $rawSample->holdingDays,
            totalQuantity: $rawSample->totalQuantity,
            openQuantity: $rawSample->openQuantity,
            avgEntryPrice: $rawSample->avgEntryPrice,
            realizedPnlGross: $rawSample->realizedPnlGross,
            realizedPnlNet: $rawSample->realizedPnlNet,
            realizedPnlPct: $rawSample->realizedPnlPct,
            entryEventId: $rawSample->entryEventId,
            exitEventId: $rawSample->exitEventId,
            exitReason: $rawSample->exitReason,
            buySignalSnapshotId: $rawSample->buySignalSnapshotId,
            sepaSnapshotId: $rawSample->sepaSnapshotId,
            epaSnapshotId: $rawSample->epaSnapshotId,
            scoringVersion: $rawSample->scoringVersion,
            policyVersion: $rawSample->policyVersion,
            modelVersion: $rawSample->modelVersion,
            macroVersion: $rawSample->macroVersion,
            seedSource: $rawSample->seedSource,
            eligibilityStatus: $result->status,
            exclusionReason: $result->exclusionReason,
            dataQualityFlags: $result->dataQualityFlags,
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
        if ($entryEvent !== null && $entryEvent['event_type'] === 'entry') {
            return 'live';
        }
        return null;
    }

    private function calculateHoldingDays(array $campaign): ?int
    {
        $openedAt = $this->parseDateTime($campaign['opened_at']);
        $closedAt = $this->parseDateTime($campaign['closed_at']);
        if ($openedAt === null || $closedAt === null) {
            return null;
        }
        return $closedAt->diff($openedAt)->days;
    }

    private function parseDateTime(?string $value): ?DateTimeImmutable
    {
        if ($value === null) {
            return null;
        }
        try {
            return new DateTimeImmutable($value);
        } catch (\Exception $e) {
            return null;
        }
    }
}
