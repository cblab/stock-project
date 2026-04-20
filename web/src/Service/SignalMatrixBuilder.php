<?php

namespace App\Service;

use Doctrine\DBAL\Connection;

class SignalMatrixBuilder
{
    private const SORT_FIELDS = [
        'ticker' => 'i.input_ticker',
        'name' => 'i.name',
        'asset' => 'i.asset_class',
        'region' => 'i.region',
        'scope' => 'pr.run_scope',
        'kronos' => 'pri.kronos_normalized_score',
        'sentiment' => 'pri.sentiment_normalized_score',
        'merged' => 'pri.merged_score',
        'decision' => 'pri.decision',
        'structure' => 's.structure_score',
        'execution' => 's.execution_score',
        'sepaTotal' => 's.total_score',
        'trafficLight' => 's.traffic_light',
        'lastRun' => 'last_run_at',
        'lastSepa' => 's.as_of_date',
    ];

    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * @return array{items: array<int, array<string, mixed>>, sort: string, direction: string}
     */
    public function build(string $sort = 'merged', string $direction = 'desc'): array
    {
        $sort = array_key_exists($sort, self::SORT_FIELDS) ? $sort : 'merged';
        $direction = strtolower($direction) === 'asc' ? 'asc' : 'desc';
        $sortExpression = self::SORT_FIELDS[$sort];
        $sqlDirection = strtoupper($direction);

        $items = $this->connection->fetchAllAssociative(
            <<<SQL
            SELECT
                i.id AS instrument_id,
                i.input_ticker,
                i.display_ticker,
                i.name,
                i.wkn,
                i.asset_class,
                i.region,
                i.active,
                i.is_portfolio,
                pri.id AS run_item_id,
                pr.id AS run_id,
                pr.run_scope,
                COALESCE(pr.finished_at, pr.started_at, pr.created_at) AS last_run_at,
                pri.kronos_normalized_score,
                pri.sentiment_normalized_score,
                pri.merged_score,
                pri.decision,
                pri.sentiment_label,
                s.as_of_date AS last_sepa_date,
                s.structure_score,
                s.execution_score,
                s.total_score AS sepa_total_score,
                s.traffic_light,
                s.detail_json AS sepa_detail_json
            FROM instrument i
            LEFT JOIN pipeline_run_item pri ON pri.id = (
                SELECT pri2.id
                FROM pipeline_run_item pri2
                INNER JOIN pipeline_run pr2 ON pr2.id = pri2.pipeline_run_id
                WHERE pri2.instrument_id = i.id
                ORDER BY COALESCE(pr2.finished_at, pr2.started_at, pr2.created_at) DESC, pr2.id DESC, pri2.id DESC
                LIMIT 1
            )
            LEFT JOIN pipeline_run pr ON pr.id = pri.pipeline_run_id
            LEFT JOIN instrument_sepa_snapshot s ON s.id = (
                SELECT s2.id
                FROM instrument_sepa_snapshot s2
                WHERE s2.instrument_id = i.id
                ORDER BY s2.as_of_date DESC, s2.id DESC
                LIMIT 1
            )
            ORDER BY ($sortExpression IS NULL) ASC, $sortExpression $sqlDirection, (s.total_score IS NULL) ASC, s.total_score DESC, i.input_ticker ASC
            SQL
        );

        return [
            'items' => array_map([$this, 'normalizeItem'], $items),
            'sort' => $sort,
            'direction' => $direction,
        ];
    }

    /** @param array<string, mixed> $item */
    private function normalizeItem(array $item): array
    {
        $item['active'] = (bool) $item['active'];
        $item['is_portfolio'] = (bool) $item['is_portfolio'];
        $item['sepa_detail'] = [];
        if (is_string($item['sepa_detail_json'] ?? null) && $item['sepa_detail_json'] !== '') {
            $decoded = json_decode($item['sepa_detail_json'], true);
            $item['sepa_detail'] = is_array($decoded) ? $decoded : [];
        }
        unset($item['sepa_detail_json']);

        return $item;
    }
}
