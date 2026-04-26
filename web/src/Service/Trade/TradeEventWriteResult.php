<?php

declare(strict_types=1);

namespace App\Service\Trade;

/**
 * Immutable DTO representing the result of a successful trade event write operation.
 *
 * This class captures all relevant IDs and state produced by TradeEventWriter,
 * allowing callers to understand what was created or updated without querying
 * the database again.
 */
final readonly class TradeEventWriteResult
{
    /**
     * @param int $tradeCampaignId The ID of the trade campaign (created or updated)
     * @param int $tradeEventId The ID of the newly created trade event
     * @param string $campaignState The current state of the campaign after the event
     * @param int|null $buySignalSnapshotId Resolved buy signal snapshot ID (if available)
     * @param int|null $sepaSnapshotId Resolved SEPA snapshot ID (if available)
     * @param int|null $epaSnapshotId Resolved EPA snapshot ID (if available)
     * @param array<string, string|null> $versions System versions applied to this event
     */
    public function __construct(
        public int $tradeCampaignId,
        public int $tradeEventId,
        public string $campaignState,
        public ?int $buySignalSnapshotId,
        public ?int $sepaSnapshotId,
        public ?int $epaSnapshotId,
        public array $versions,
    ) {
    }

    /**
     * Convert the result to an associative array.
     *
     * @return array{
     *     trade_campaign_id: int,
     *     trade_event_id: int,
     *     campaign_state: string,
     *     buy_signal_snapshot_id: int|null,
     *     sepa_snapshot_id: int|null,
     *     epa_snapshot_id: int|null,
     *     versions: array{
     *         scoring_version: string|null,
     *         policy_version: string|null,
     *         model_version: string|null,
     *         macro_version: string|null,
     *     },
     * }
     */
    public function toArray(): array
    {
        return [
            'trade_campaign_id' => $this->tradeCampaignId,
            'trade_event_id' => $this->tradeEventId,
            'campaign_state' => $this->campaignState,
            'buy_signal_snapshot_id' => $this->buySignalSnapshotId,
            'sepa_snapshot_id' => $this->sepaSnapshotId,
            'epa_snapshot_id' => $this->epaSnapshotId,
            'versions' => [
                'scoring_version' => $this->versions['scoring_version'] ?? null,
                'policy_version' => $this->versions['policy_version'] ?? null,
                'model_version' => $this->versions['model_version'] ?? null,
                'macro_version' => $this->versions['macro_version'] ?? null,
            ],
        ];
    }
}
