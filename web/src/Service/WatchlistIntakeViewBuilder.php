<?php

namespace App\Service;

use Doctrine\DBAL\Connection;

class WatchlistIntakeViewBuilder
{
    public function __construct(private readonly Connection $connection)
    {
    }

    /** @return array{run: ?array<string, mixed>, sectors: array<int, array<string, mixed>>, candidates: array<int, array<string, mixed>>} */
    public function latest(): array
    {
        $run = $this->connection->fetchAssociative(
            'SELECT * FROM sector_intake_run ORDER BY created_at DESC, id DESC LIMIT 1'
        ) ?: null;

        if ($run === null) {
            return ['run' => null, 'sectors' => [], 'candidates' => []];
        }

        $sectors = $this->connection->fetchAllAssociative(
            'SELECT * FROM sector_intake_sector WHERE run_id = ? ORDER BY sector_rank ASC',
            [$run['id']]
        );
        $candidates = $this->connection->fetchAllAssociative(
            'SELECT * FROM sector_intake_candidate WHERE run_id = ? ORDER BY sector_rank ASC, candidate_rank ASC, intake_score DESC',
            [$run['id']]
        );

        return [
            'run' => $this->normalizeJson($run, ['config_json', 'summary_json']),
            'sectors' => array_map(fn (array $row) => $this->normalizeJson($row, ['detail_json']), $sectors),
            'candidates' => array_map(fn (array $row) => $this->normalizeJson($row, ['hard_checks_json', 'detail_json']), $candidates),
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
