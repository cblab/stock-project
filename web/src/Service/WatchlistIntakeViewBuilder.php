<?php

namespace App\Service;

use Doctrine\DBAL\Connection;

class WatchlistIntakeViewBuilder
{
    public function __construct(private readonly Connection $connection)
    {
    }

    /** @return array{run: ?array<string, mixed>, sectors: array<int, array<string, mixed>>, candidates: array<int, array<string, mixed>>, pagination: array<string, int>} */
    public function latest(int $page = 1, int $perPage = 10): array
    {
        $page = max(1, $page);
        $perPage = max(5, min(100, $perPage));
        $run = $this->connection->fetchAssociative(
            'SELECT * FROM sector_intake_run ORDER BY created_at DESC, id DESC LIMIT 1'
        ) ?: null;

        if ($run === null) {
            return ['run' => null, 'sectors' => [], 'candidates' => [], 'pagination' => $this->pagination(1, $perPage, 0)];
        }

        $sectors = $this->connection->fetchAllAssociative(
            'SELECT * FROM sector_intake_sector WHERE run_id = ? ORDER BY sector_rank ASC',
            [$run['id']]
        );
        $totalCandidates = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM sector_intake_candidate WHERE run_id = ?', [$run['id']]);
        $pages = max(1, (int) ceil($totalCandidates / $perPage));
        $page = min($page, $pages);
        $offset = ($page - 1) * $perPage;
        $candidates = $this->connection->fetchAllAssociative(
            'SELECT * FROM sector_intake_candidate WHERE run_id = ? ORDER BY sector_rank ASC, candidate_rank ASC, intake_score DESC LIMIT '.$perPage.' OFFSET '.$offset,
            [$run['id']]
        );

        return [
            'run' => $this->normalizeJson($run, ['config_json', 'summary_json']),
            'sectors' => array_map(fn (array $row) => $this->normalizeJson($row, ['detail_json']), $sectors),
            'candidates' => array_map(fn (array $row) => $this->normalizeJson($row, ['hard_checks_json', 'detail_json']), $candidates),
            'pagination' => $this->pagination($page, $perPage, $totalCandidates),
        ];
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
