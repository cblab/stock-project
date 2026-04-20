<?php

namespace App\Service;

use Doctrine\DBAL\Connection;

class WatchlistIntakeViewBuilder
{
    public function __construct(private readonly Connection $connection)
    {
    }

    /** @return array{run: ?array<string, mixed>, sectors: array<int, array<string, mixed>>, candidates: array<int, array<string, mixed>>, pagination: array<string, int>, metrics: array<string, int>} */
    public function latest(int $page = 1, int $perPage = 10): array
    {
        $page = max(1, $page);
        $perPage = max(5, min(100, $perPage));
        $run = $this->connection->fetchAssociative(
            'SELECT * FROM sector_intake_run ORDER BY created_at DESC, id DESC LIMIT 1'
        ) ?: null;

        if ($run === null) {
            return ['run' => null, 'sectors' => [], 'candidates' => [], 'pagination' => $this->pagination(1, $perPage, 0), 'metrics' => $this->emptyMetrics()];
        }

        $sectors = $this->connection->fetchAllAssociative(
            'SELECT * FROM sector_intake_sector WHERE run_id = ? ORDER BY sector_rank ASC',
            [$run['id']]
        );
        $metrics = $this->registryMetrics();
        $totalCandidates = $metrics['total_candidates'];
        $pages = max(1, (int) ceil($totalCandidates / $perPage));
        $page = min($page, $pages);
        $offset = ($page - 1) * $perPage;
        $candidates = $this->connection->fetchAllAssociative(
            'SELECT * FROM watchlist_candidate_registry ORDER BY active_candidate DESC, COALESCE(manual_state, latest_status) ASC, latest_intake_score DESC, last_seen_at DESC LIMIT '.$perPage.' OFFSET '.$offset
        );

        return [
            'run' => $this->normalizeJson($run, ['config_json', 'summary_json']),
            'sectors' => array_map(fn (array $row) => $this->normalizeJson($row, ['detail_json']), $sectors),
            'candidates' => array_map(fn (array $row) => $this->normalizeRegistryRow($row), $candidates),
            'pagination' => $this->pagination($page, $perPage, $totalCandidates),
            'metrics' => $metrics,
        ];
    }

    /** @return array<string, int> */
    private function registryMetrics(): array
    {
        $row = $this->connection->fetchAssociative(
            "SELECT
                COUNT(*) AS total_candidates,
                SUM(active_candidate = 1) AS active_candidates,
                SUM(manual_state = 'ADDED_TO_WATCHLIST') AS manually_added,
                SUM(manual_state = 'DISMISSED') AS dismissed,
                SUM(manual_state = 'RECHECK_LATER') AS recheck_later,
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
            'dismissed' => 0,
            'recheck_later' => 0,
            'top_candidates' => 0,
            'strong_candidates' => 0,
        ];
    }

    private function normalizeRegistryRow(array $row): array
    {
        $row = $this->normalizeJson($row, ['latest_buy_signals_json', 'latest_sepa_json', 'latest_epa_json', 'latest_detail_json']);
        $row['status'] = $row['manual_state'] ?: $row['latest_status'];
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

    /** @return array{page: int, per_page: int, total: int, pages: int} */
    private function pagination(int $page, int $perPage, int $total): array
    {
        return [
            'page' => $page,
            'per_page' => $perPage,
            'total' => $total,
            'pages' => max(1, (int) ceil($total / $perPage)),
        ];
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
