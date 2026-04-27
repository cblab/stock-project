<?php

namespace App\Command;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsCommand(name: 'app:trade:legacy-seed-template', description: 'Generate manual seed template for legacy portfolio instruments.')]
class TradeLegacySeedTemplateCommand extends Command
{
    public function __construct(
        private readonly Connection $connection,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('instrument', 'i', InputOption::VALUE_REQUIRED, 'Filter by instrument ID')
            ->addOption('format', 'f', InputOption::VALUE_REQUIRED, 'Output format (csv|json)', 'csv')
            ->addOption('output', 'o', InputOption::VALUE_REQUIRED, 'Output file path');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $instrumentId = $input->getOption('instrument');
        $format = $input->getOption('format');
        $outputPath = $input->getOption('output');

        if ($instrumentId !== null && !is_numeric($instrumentId)) {
            $io->error('Invalid instrument ID');
            return Command::INVALID;
        }

        if (!in_array($format, ['csv', 'json'], true)) {
            $io->error('Invalid format. Use csv or json.');
            return Command::INVALID;
        }

        $instrumentFilter = $instrumentId !== null ? (int) $instrumentId : null;
        $instruments = $this->findLegacyInstruments($instrumentFilter);

        if (empty($instruments)) {
            $io->success('No legacy portfolio instruments found (all portfolio instruments have trade campaigns).');
            return Command::SUCCESS;
        }

        $rows = array_map(fn($i) => $this->buildTemplateRow($i), $instruments);

        if ($format === 'json') {
            $content = json_encode(['count' => count($rows), 'rows' => $rows], JSON_PRETTY_PRINT);
        } else {
            $content = $this->generateCsv($rows);
        }

        if ($outputPath) {
            $fullPath = $this->resolveOutputPath($outputPath);
            $dir = dirname($fullPath);
            if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
                $io->error("Cannot create directory: {$dir}");
                return Command::FAILURE;
            }
            file_put_contents($fullPath, $content);
            $io->success(sprintf('Template written to: %s', $fullPath));
        } else {
            $output->writeln($content);
        }

        return Command::SUCCESS;
    }

    private function findLegacyInstruments(?int $instrumentId): array
    {
        $filter = $instrumentId ? " AND i.id = {$instrumentId}" : '';

        $sql = "SELECT i.id as instrument_id, i.input_ticker, i.display_ticker, i.name,
                i.wkn, i.isin, i.region
            FROM instrument i
            LEFT JOIN trade_campaign tc ON i.id = tc.instrument_id
            WHERE i.is_portfolio = 1 AND tc.id IS NULL{$filter}
            ORDER BY i.input_ticker";

        return $this->connection->fetchAllAssociative($sql);
    }

    private function findLatestSepaSnapshotId(int $instrumentId): ?int
    {
        $sql = "SELECT id FROM instrument_sepa_snapshot
            WHERE instrument_id = ?
            ORDER BY as_of_date DESC, id DESC
            LIMIT 1";

        $result = $this->connection->fetchOne($sql, [$instrumentId]);
        return $result !== false ? (int) $result : null;
    }

    private function findLatestEpaSnapshotId(int $instrumentId): ?int
    {
        $sql = "SELECT id FROM instrument_epa_snapshot
            WHERE instrument_id = ?
            ORDER BY as_of_date DESC, id DESC
            LIMIT 1";

        $result = $this->connection->fetchOne($sql, [$instrumentId]);
        return $result !== false ? (int) $result : null;
    }

    private function buildTemplateRow(array $instrument): array
    {
        $instrumentId = (int) $instrument['instrument_id'];
        $sepaId = $this->findLatestSepaSnapshotId($instrumentId);
        $epaId = $this->findLatestEpaSnapshotId($instrumentId);

        return [
            'instrument_id' => $instrumentId,
            'input_ticker' => $instrument['input_ticker'],
            'display_ticker' => $instrument['display_ticker'] ?? '',
            'name' => $instrument['name'] ?? '',
            'wkn' => $instrument['wkn'] ?? '',
            'isin' => $instrument['isin'] ?? '',
            'trade_type' => 'MANUAL_REQUIRED',
            'quantity' => '',
            'avg_entry_price' => '',
            'opened_at' => '',
            'currency' => 'EUR',
            'fees' => '0',
            'entry_thesis' => 'Legacy portfolio seed - manual review required',
            'invalidation_rule' => '',
            'event_notes' => 'Legacy seed input template - not applied',
            'candidate_sepa_snapshot_id' => $sepaId ?? '',
            'candidate_epa_snapshot_id' => $epaId ?? '',
            'warning' => 'Manual quantity, avg_entry_price and opened_at required. Snapshot IDs are candidates only.',
        ];
    }

    private function generateCsv(array $rows): string
    {
        if (empty($rows)) {
            return '';
        }

        $headers = array_keys($rows[0]);
        $output = fopen('php://temp', 'r+');

        // UTF-8 BOM for Excel
        fwrite($output, "\xEF\xBB\xBF");

        // Headers
        fputcsv($output, $headers, ';');

        // Data rows
        foreach ($rows as $row) {
            fputcsv($output, $row, ';');
        }

        rewind($output);
        $content = stream_get_contents($output);
        fclose($output);

        return $content;
    }

    private function resolveOutputPath(string $path): string
    {
        if (str_starts_with($path, '/')) {
            return $path;
        }
        return $this->projectDir . '/' . $path;
    }
}
