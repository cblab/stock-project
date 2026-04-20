<?php

namespace App\Service;

use Doctrine\DBAL\Connection;

class SellSignalMatrixBuilder
{
    private const SORT_FIELDS = [
        'ticker' => 'i.input_ticker',
        'name' => 'i.name',
        'scope' => 'i.is_portfolio',
        'action' => 'e.action',
        'total' => 'e.total_score',
        'failure' => 'e.failure_score',
        'trend' => 'e.trend_exit_score',
        'climax' => 'e.climax_score',
        'risk' => 'e.risk_score',
        'asOf' => 'e.as_of_date',
    ];

    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * @return array{items: array<int, array<string, mixed>>, sort: string, direction: string}
     */
    public function build(string $sort = 'total', string $direction = 'desc'): array
    {
        $sort = array_key_exists($sort, self::SORT_FIELDS) ? $sort : 'total';
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
                e.as_of_date AS epa_as_of_date,
                e.failure_score,
                e.trend_exit_score,
                e.climax_score,
                e.risk_score,
                e.total_score AS epa_total_score,
                e.action,
                e.hard_triggers_json,
                e.soft_warnings_json,
                e.detail_json,
                pr.id AS run_id,
                COALESCE(pr.finished_at, pr.started_at, pr.created_at) AS last_run_at
            FROM instrument i
            LEFT JOIN instrument_epa_snapshot e ON e.id = (
                SELECT e2.id
                FROM instrument_epa_snapshot e2
                WHERE e2.instrument_id = i.id
                ORDER BY e2.as_of_date DESC, e2.id DESC
                LIMIT 1
            )
            LEFT JOIN pipeline_run_item pri ON pri.id = (
                SELECT pri2.id
                FROM pipeline_run_item pri2
                INNER JOIN pipeline_run pr2 ON pr2.id = pri2.pipeline_run_id
                WHERE pri2.instrument_id = i.id
                ORDER BY COALESCE(pr2.finished_at, pr2.started_at, pr2.created_at) DESC, pr2.id DESC, pri2.id DESC
                LIMIT 1
            )
            LEFT JOIN pipeline_run pr ON pr.id = pri.pipeline_run_id
            WHERE i.active = 1
            ORDER BY ($sortExpression IS NULL) ASC, $sortExpression $sqlDirection, (e.total_score IS NULL) ASC, e.total_score DESC, i.input_ticker ASC
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
        $item['hard_triggers'] = $this->decodeJson($item['hard_triggers_json'] ?? null);
        $item['soft_warnings'] = $this->decodeJson($item['soft_warnings_json'] ?? null);
        $item['epa_detail'] = $this->decodeJson($item['detail_json'] ?? null);
        unset($item['hard_triggers_json'], $item['soft_warnings_json'], $item['detail_json']);

        return $item;
    }

    private function decodeJson(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (!is_string($value) || $value === '') {
            return [];
        }
        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : [];
    }
}
