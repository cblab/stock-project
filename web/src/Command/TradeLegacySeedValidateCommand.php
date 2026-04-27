<?php

namespace App\Command;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:trade:legacy-seed-validate', description: 'Validate legacy seed template CSV.')]
class TradeLegacySeedValidateCommand extends Command
{
    private const REQUIRED_COLUMNS = [
        'instrument_id',
        'input_ticker',
        'trade_type',
        'quantity',
        'avg_entry_price',
        'opened_at',
        'currency',
        'fees',
        'candidate_sepa_snapshot_id',
        'candidate_epa_snapshot_id',
    ];

    private const VALID_TRADE_TYPES = ['live', 'paper', 'pseudo'];

    public function __construct(
        private readonly Connection $connection,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('input', 'i', InputOption::VALUE_REQUIRED, 'Input CSV file path')
            ->addOption('format', 'f', InputOption::VALUE_REQUIRED, 'Output format (text|json)', 'text');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $inputPath = $input->getOption('input');
        $format = $input->getOption('format');

        if (!in_array($format, ['text', 'json'], true)) {
            $io->error('Invalid format. Use text or json.');
            return Command::INVALID;
        }

        if (!$inputPath) {
            if ($format === 'json') {
                $output->writeln(json_encode(['error' => 'Input file path is required (--input=PATH)'], JSON_PRETTY_PRINT));
                return Command::INVALID;
            }
            $io->error('Input file path is required (--input=PATH)');
            return Command::INVALID;
        }

        if (!file_exists($inputPath) || !is_readable($inputPath)) {
            if ($format === 'json') {
                $output->writeln(json_encode(['error' => "File not found or not readable: {$inputPath}"], JSON_PRETTY_PRINT));
                return Command::INVALID;
            }
            $io->error("File not found or not readable: {$inputPath}");
            return Command::INVALID;
        }

        $rows = $this->parseCsv($inputPath);

        if ($rows === null) {
            if ($format === 'json') {
                $output->writeln(json_encode(['error' => 'Failed to parse CSV file'], JSON_PRETTY_PRINT));
                return Command::INVALID;
            }
            $io->error('Failed to parse CSV file');
            return Command::INVALID;
        }

        if (empty($rows)) {
            if ($format === 'json') {
                $output->writeln(json_encode([
                    'total_rows' => 0,
                    'valid_rows' => 0,
                    'invalid_rows' => 0,
                    'rows' => [],
                ], JSON_PRETTY_PRINT));
                return Command::SUCCESS;
            }
            $io->warning('CSV file is empty');
            return Command::SUCCESS;
        }

        $results = $this->validateRows($rows);

        $validCount = count(array_filter($results, fn($r) => $r['status'] === 'valid'));
        $invalidCount = count($results) - $validCount;

        if ($format === 'json') {
            $output->writeln(json_encode([
                'total_rows' => count($results),
                'valid_rows' => $validCount,
                'invalid_rows' => $invalidCount,
                'rows' => $results,
            ], JSON_PRETTY_PRINT));
            return $invalidCount > 0 ? Command::FAILURE : Command::SUCCESS;
        }

        $this->renderTextOutput($io, $results, $validCount, $invalidCount);
        return $invalidCount > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    private function parseCsv(string $path): ?array
    {
        $handle = fopen($path, 'r');
        if (!$handle) {
            return null;
        }

        // Skip BOM if present
        $bom = fread($handle, 3);
        if ($bom !== "\xEF\xBB\xBF") {
            rewind($handle);
        }

        $headers = fgetcsv($handle, 0, ';');
        if (!$headers) {
            fclose($handle);
            return null;
        }

        // Check required columns
        $missingColumns = array_diff(self::REQUIRED_COLUMNS, $headers);
        if (!empty($missingColumns)) {
            fclose($handle);
            return null;
        }

        $rows = [];
        $rowNumber = 2; // Header is row 1
        while (($data = fgetcsv($handle, 0, ';')) !== false) {
            if (count($data) === count($headers)) {
                $rows[] = [
                    'row_number' => $rowNumber,
                    'data' => array_combine($headers, $data),
                ];
            }
            $rowNumber++;
        }

        fclose($handle);
        return $rows;
    }

    private function validateRows(array $rows): array
    {
        $results = [];
        $seenInstrumentIds = [];

        foreach ($rows as $row) {
            $rowNumber = $row['row_number'];
            $data = $row['data'];
            $errors = [];

            $instrumentId = (int) ($data['instrument_id'] ?? 0);
            $inputTicker = $data['input_ticker'] ?? '';

            // Check duplicate instrument_id
            if (in_array($instrumentId, $seenInstrumentIds, true)) {
                $errors[] = "Duplicate instrument_id: {$instrumentId}";
            } else {
                $seenInstrumentIds[] = $instrumentId;
            }

            // Validate instrument exists
            $instrument = $this->connection->fetchAssociative(
                "SELECT id, input_ticker, is_portfolio FROM instrument WHERE id = ?",
                [$instrumentId]
            );

            if (!$instrument) {
                $errors[] = "Instrument ID {$instrumentId} does not exist";
            } else {
                // Check is_portfolio
                if (!$instrument['is_portfolio']) {
                    $errors[] = "Instrument is not marked as portfolio (is_portfolio=0)";
                }

                // Check input_ticker matches
                if ($instrument['input_ticker'] !== $inputTicker) {
                    $errors[] = "input_ticker mismatch: CSV='{$inputTicker}', DB='{$instrument['input_ticker']}'";
                }

                // Check no existing trade_campaign
                $hasCampaign = $this->connection->fetchOne(
                    "SELECT 1 FROM trade_campaign WHERE instrument_id = ?",
                    [$instrumentId]
                );
                if ($hasCampaign) {
                    $errors[] = "Instrument already has a trade_campaign";
                }
            }

            // Validate trade_type
            $tradeType = $data['trade_type'] ?? '';
            if (!in_array($tradeType, self::VALID_TRADE_TYPES, true)) {
                $errors[] = "Invalid trade_type: '{$tradeType}'. Must be one of: " . implode(', ', self::VALID_TRADE_TYPES);
            }

            // Validate quantity
            $quantity = $data['quantity'] ?? '';
            if ($quantity === '' || !is_numeric($quantity) || (float) $quantity <= 0) {
                $errors[] = "Invalid quantity: '{$quantity}'. Must be numeric and > 0";
            }

            // Validate avg_entry_price
            $avgPrice = $data['avg_entry_price'] ?? '';
            if ($avgPrice === '' || !is_numeric($avgPrice) || (float) $avgPrice <= 0) {
                $errors[] = "Invalid avg_entry_price: '{$avgPrice}'. Must be numeric and > 0";
            }

            // Validate fees
            $fees = $data['fees'] ?? '';
            if ($fees === '' || !is_numeric($fees) || (float) $fees < 0) {
                $errors[] = "Invalid fees: '{$fees}'. Must be numeric and >= 0";
            }

            // Validate currency
            $currency = $data['currency'] ?? '';
            if (strlen($currency) !== 3) {
                $errors[] = "Invalid currency: '{$currency}'. Must be 3 characters (e.g., EUR)";
            }

            // Validate opened_at
            $openedAt = $data['opened_at'] ?? '';
            $openedAtDate = null;
            if ($openedAt === '') {
                $errors[] = "opened_at is required";
            } else {
                $openedAtDate = \DateTime::createFromFormat('Y-m-d H:i:s', $openedAt);
                if (!$openedAtDate) {
                    $openedAtDate = \DateTime::createFromFormat('Y-m-d', $openedAt);
                }
                if (!$openedAtDate) {
                    $errors[] = "Invalid opened_at format: '{$openedAt}'. Use YYYY-MM-DD HH:MM:SS or YYYY-MM-DD";
                }
            }

            // Validate candidate_sepa_snapshot_id
            $sepaSnapshotId = $data['candidate_sepa_snapshot_id'] ?? '';
            if ($sepaSnapshotId !== '') {
                $sepaSnapshot = $this->connection->fetchAssociative(
                    "SELECT id, instrument_id, as_of_date FROM instrument_sepa_snapshot WHERE id = ?",
                    [(int) $sepaSnapshotId]
                );
                if (!$sepaSnapshot) {
                    $errors[] = "candidate_sepa_snapshot_id {$sepaSnapshotId} does not exist";
                } else {
                    if ((int) $sepaSnapshot['instrument_id'] !== $instrumentId) {
                        $errors[] = "SEPA snapshot {$sepaSnapshotId} does not belong to instrument {$instrumentId}";
                    }
                    if ($openedAtDate) {
                        $snapshotDate = new \DateTime($sepaSnapshot['as_of_date']);
                        if ($snapshotDate > $openedAtDate) {
                            $errors[] = "SEPA snapshot date ({$sepaSnapshot['as_of_date']}) is after opened_at ({$openedAt})";
                        }
                    }
                }
            }

            // Validate candidate_epa_snapshot_id
            $epaSnapshotId = $data['candidate_epa_snapshot_id'] ?? '';
            if ($epaSnapshotId !== '') {
                $epaSnapshot = $this->connection->fetchAssociative(
                    "SELECT id, instrument_id, as_of_date FROM instrument_epa_snapshot WHERE id = ?",
                    [(int) $epaSnapshotId]
                );
                if (!$epaSnapshot) {
                    $errors[] = "candidate_epa_snapshot_id {$epaSnapshotId} does not exist";
                } else {
                    if ((int) $epaSnapshot['instrument_id'] !== $instrumentId) {
                        $errors[] = "EPA snapshot {$epaSnapshotId} does not belong to instrument {$instrumentId}";
                    }
                    if ($openedAtDate) {
                        $snapshotDate = new \DateTime($epaSnapshot['as_of_date']);
                        if ($snapshotDate > $openedAtDate) {
                            $errors[] = "EPA snapshot date ({$epaSnapshot['as_of_date']}) is after opened_at ({$openedAt})";
                        }
                    }
                }
            }

            $results[] = [
                'row_number' => $rowNumber,
                'instrument_id' => $instrumentId,
                'input_ticker' => $inputTicker,
                'status' => empty($errors) ? 'valid' : 'invalid',
                'errors' => $errors,
            ];
        }

        return $results;
    }

    private function renderTextOutput(SymfonyStyle $io, array $results, int $validCount, int $invalidCount): void
    {
        $io->title('Legacy Seed Template Validation');

        $io->section('Summary');
        $io->text(sprintf('Total rows:  %d', count($results)));
        $io->text(sprintf('Valid rows:  %d', $validCount));
        $io->text(sprintf('Invalid rows: %d', $invalidCount));

        if ($invalidCount > 0) {
            $io->newLine();
            $io->section('Errors by Row');

            foreach ($results as $result) {
                if ($result['status'] === 'invalid') {
                    $io->text(sprintf(
                        'Row %d (ID: %d, %s):',
                        $result['row_number'],
                        $result['instrument_id'],
                        $result['input_ticker']
                    ));
                    foreach ($result['errors'] as $error) {
                        $io->text('  • ' . $error);
                    }
                    $io->newLine();
                }
            }
        }

        if ($invalidCount === 0) {
            $io->newLine();
            $io->success('All rows are valid.');
        } else {
            $io->warning(sprintf('Found %d invalid row(s).', $invalidCount));
        }
    }
}