<?php

namespace App\Service;

use Doctrine\DBAL\Connection;

class WatchlistIntakeViewBuilder
{
    public function __construct(private readonly Connection $connection)
    {
    }

    private const SORT_COLUMNS = [
        'priority' => 'registry_priority',
        'ticker' => 'ticker',
        'sector' => 'sector_label',
        'status' => 'registry_status',
        'sepa' => 'sepa_total',
        'merged' => 'merged_score',
        'pruefung' => 'last_seen_at',
        'seen' => 'seen_count',
    ];

    /** @return array{run: ?array<string, mixed>, sectors: array<int, array<string, mixed>>, candidates: array<int, array<string, mixed>>, pagination: array<string, mixed>, metrics: array<string, int>, sort: array{key: string, dir: string}, showRejected: bool} */
    public function latest(int $page = 1, int $perPage = 10, string $sort = 'priority', string $dir = 'desc', bool $showRejected = false): array
    {
        $page = max(1, $page);
        $perPage = max(5, min(100, $perPage));
        $sort = array_key_exists($sort, self::SORT_COLUMNS) ? $sort : 'priority';
        $dir = strtolower($dir) === 'asc' ? 'asc' : 'desc';
        $run = $this->connection->fetchAssociative(
            'SELECT * FROM sector_intake_run ORDER BY created_at DESC, id DESC LIMIT 1'
        ) ?: null;

        if ($run === null) {
            return ['run' => null, 'sectors' => [], 'candidates' => [], 'pagination' => $this->pagination(1, $perPage, 0), 'metrics' => $this->emptyMetrics(), 'sort' => ['key' => $sort, 'dir' => $dir], 'showRejected' => $showRejected];
        }

        $sectors = $this->connection->fetchAllAssociative(
            'SELECT * FROM sector_intake_sector WHERE run_id = ? ORDER BY sector_rank ASC',
            [$run['id']]
        );
        $metrics = $this->registryMetrics();
        $where = $showRejected ? '' : " WHERE COALESCE(manual_state, latest_status, 'ACTIVE_CANDIDATE') <> 'REJECTED'";
        $totalCandidates = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM watchlist_candidate_registry'.$where);
        $pages = max(1, (int) ceil($totalCandidates / $perPage));
        $page = min($page, $pages);
        $offset = ($page - 1) * $perPage;
        $orderExpression = self::SORT_COLUMNS[$sort];
        $orderSql = $orderExpression.' '.$dir.', registry_priority DESC, latest_intake_score DESC, last_seen_at DESC, ticker ASC';
        $candidates = $this->connection->fetchAllAssociative(
            $this->registrySelectSql().$where.' ORDER BY '.$orderSql.' LIMIT '.$perPage.' OFFSET '.$offset
        );

        return [
            'run' => $this->normalizeJson($run, ['config_json', 'summary_json']),
            'sectors' => array_map(fn (array $row) => $this->normalizeJson($row, ['detail_json']), $sectors),
            'candidates' => array_map(fn (array $row) => $this->normalizeRegistryRow($row), $candidates),
            'pagination' => $this->pagination($page, $perPage, $totalCandidates),
            'metrics' => $metrics,
            'sort' => ['key' => $sort, 'dir' => $dir],
            'showRejected' => $showRejected,
        ];
    }

    private function registrySelectSql(): string
    {
        return "
            SELECT
                r.*,
                COALESCE(r.manual_state, r.latest_status, 'ACTIVE_CANDIDATE') AS registry_status,
                CAST(JSON_UNQUOTE(JSON_EXTRACT(r.latest_sepa_json, '$.total')) AS DECIMAL(10,4)) AS sepa_total,
                CAST(JSON_UNQUOTE(JSON_EXTRACT(r.latest_buy_signals_json, '$.merged_score')) AS DECIMAL(10,4)) AS merged_score,
                (
                    r.latest_intake_score * 0.35
                    + r.best_intake_score * 0.25
                    + LEAST(r.seen_count, 5) * 4
                    + CASE
                        WHEN r.last_seen_at >= DATE_SUB(NOW(), INTERVAL 3 DAY) THEN 10
                        WHEN r.last_seen_at >= DATE_SUB(NOW(), INTERVAL 14 DAY) THEN 6
                        WHEN r.last_seen_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) THEN 3
                        ELSE 0
                      END
                    + CASE COALESCE(r.manual_state, r.latest_status)
                        WHEN 'TOP_CANDIDATE' THEN 15
                        WHEN 'STRONG_CANDIDATE' THEN 9
                        WHEN 'RESEARCH_ONLY' THEN 2
                        WHEN 'ADDED_TO_WATCHLIST' THEN -20
                        WHEN 'REJECTED' THEN -25
                        ELSE 0
                      END
                    + COALESCE(CAST(JSON_UNQUOTE(JSON_EXTRACT(r.latest_sepa_json, '$.total')) AS DECIMAL(10,4)), 0) * 0.15
                    + CASE
                        WHEN CAST(JSON_UNQUOTE(JSON_EXTRACT(r.latest_buy_signals_json, '$.merged_score')) AS DECIMAL(10,4)) IS NULL THEN 0
                        WHEN ABS(CAST(JSON_UNQUOTE(JSON_EXTRACT(r.latest_buy_signals_json, '$.merged_score')) AS DECIMAL(10,4))) <= 1
                            THEN ((CAST(JSON_UNQUOTE(JSON_EXTRACT(r.latest_buy_signals_json, '$.merged_score')) AS DECIMAL(10,4)) + 1) * 50) * 0.05
                        ELSE CAST(JSON_UNQUOTE(JSON_EXTRACT(r.latest_buy_signals_json, '$.merged_score')) AS DECIMAL(10,4)) * 0.05
                      END
                    + CASE WHEN r.active_candidate = 1 THEN 5 ELSE -20 END
                ) AS registry_priority
            FROM watchlist_candidate_registry r
        ";
    }

    /** @return array<string, int> */
    private function registryMetrics(): array
    {
        $row = $this->connection->fetchAssociative(
            "SELECT
                COUNT(*) AS total_candidates,
                SUM(active_candidate = 1) AS active_candidates,
                SUM(manual_state = 'ADDED_TO_WATCHLIST') AS manually_added,
                SUM(manual_state = 'REJECTED') AS rejected,
                SUM(COALESCE(manual_state, latest_status) = 'TOP_CANDIDATE') AS top_candidates,
                SUM(COALESCE(manual_state, latest_status) = 'STRONG_CANDIDATE') AS strong_candidates
            FROM watchlist_candidate_registry"
        ) ?: [];

        return array_map(fn ($value) => (int) ($value ?? 0), array_merge($this->emptyMetrics(), $row));
    }

    /** @return array<string, int> */
    private function emptyMetrics(): array
    {
        return [
            'total_candidates' => 0,
            'active_candidates' => 0,
            'manually_added' => 0,
            'rejected' => 0,
            'top_candidates' => 0,
            'strong_candidates' => 0,
        ];
    }

    private function normalizeRegistryRow(array $row): array
    {
        $row = $this->normalizeJson($row, ['latest_buy_signals_json', 'latest_sepa_json', 'latest_epa_json', 'latest_detail_json']);
        $row['status'] = $row['registry_status'] ?: 'ACTIVE_CANDIDATE';
        $row['proposal_status'] = $row['latest_status'];
        $row['intake_score'] = $row['latest_intake_score'];
        $row['reason'] = $row['latest_reason'];
        $row['hard_checks'] = array_merge(
            $row['latest_buy_signals'] ?? [],
            [
                'sepa_structure' => $row['latest_sepa']['structure'] ?? null,
                'sepa_execution' => $row['latest_sepa']['execution'] ?? null,
                'sepa_total' => $row['latest_sepa']['total'] ?? null,
                'traffic_light' => $row['latest_sepa']['traffic_light'] ?? null,
                'signal_source' => $row['latest_sepa']['source'] ?? null,
            ],
            [
                'epa_total' => $row['latest_epa']['total'] ?? null,
                'epa_climax' => $row['latest_epa']['climax'] ?? null,
                'epa_action' => $row['latest_epa']['action'] ?? null,
            ],
        );
        $row['detail'] = $row['latest_detail'] ?? [];
        $row['sector_rank'] = null;
        $row['candidate_rank'] = $row['seen_count'];

        return $row;
    }

    /** @return array{page: int, per_page: int, total: int, pages: int, page_items: array<int, int|string>} */
    private function pagination(int $page, int $perPage, int $total): array
    {
        $pages = max(1, (int) ceil($total / $perPage));

        return [
            'page' => $page,
            'per_page' => $perPage,
            'total' => $total,
            'pages' => $pages,
            'page_items' => $this->pageItems($page, $pages),
        ];
    }

    /** @return array<int, int|string> */
    private function pageItems(int $page, int $pages): array
    {
        if ($pages <= 7) {
            return range(1, $pages);
        }

        $visible = [1, $pages];
        for ($candidate = $page - 2; $candidate <= $page + 2; $candidate++) {
            if ($candidate > 1 && $candidate < $pages) {
                $visible[] = $candidate;
            }
        }

        sort($visible);
        $items = [];
        $previous = null;
        foreach (array_values(array_unique($visible)) as $item) {
            if ($previous !== null && $item > $previous + 1) {
                $items[] = '...';
            }
            $items[] = $item;
            $previous = $item;
        }

        return $items;
    }

    /** @param string[] $jsonFields */
    private function normalizeJson(array $row, array $jsonFields): array
    {
        foreach ($jsonFields as $field) {
            $decoded = [];
            if (isset($row[$field]) && is_string($row[$field]) && $row[$field] !== '') {
                $value = json_decode($row[$field], true);
                $decoded = is_array($value) ? $value : [];
            }
            $row[str_replace('_json', '', $field)] = $decoded;
            unset($row[$field]);
        }

        return $row;
    }
}
