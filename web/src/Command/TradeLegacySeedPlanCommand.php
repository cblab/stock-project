<?php

namespace App\Command;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:trade:legacy-seed-plan', description: 'Generate legacy seed plan for portfolio instruments without trade campaigns.')]
class TradeLegacySeedPlanCommand extends Command
{
    private const SYSTEM_VERSIONS = [
        'scoring_version' => 'v0.4.0',
        'policy_version' => 'v0.4.0',
        'model_version' => 'v0.4.0',
        'macro_version' => 'v0.4.0',
    ];

    public function __construct(
        private readonly Connection $connection,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('instrument', 'i', InputOption::VALUE_REQUIRED, 'Filter by instrument ID')
            ->addOption('format', 'f', InputOption::VALUE_REQUIRED, 'Output format (text|json)', 'text');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $instrumentId = $input->getOption('instrument');
        $format = $input->getOption('format');

        if ($instrumentId !== null && !is_numeric($instrumentId)) {
            $io->error('Invalid instrument ID');
            return Command::INVALID;
        }

        $instrumentFilter = $instrumentId !== null ? (int) $instrumentId : null;
        $instruments = $this->findLegacyInstruments($instrumentFilter);

        if (empty($instruments)) {
            if ($format === 'json') {
                $output->writeln(json_encode(['count' => 0, 'instruments' => []], JSON_PRETTY_PRINT));
                return Command::SUCCESS;
            }
            $io->success('No legacy portfolio instruments found (all portfolio instruments have trade campaigns).');
            return Command::SUCCESS;
        }

        $plans = array_map(fn($i) => $this->buildSeedPlan($i), $instruments);

        if ($format === 'json') {
            $output->writeln(json_encode([
                'count' => count($plans),
                'system_versions' => self::SYSTEM_VERSIONS,
                'instruments' => $plans,
            ], JSON_PRETTY_PRINT));
            return Command::SUCCESS;
        }

        $this->renderTextOutput($io, $plans);
        return Command::SUCCESS;
    }

    private function findLegacyInstruments(?int $instrumentId): array
    {
        $filter = $instrumentId ? " AND i.id = {$instrumentId}" : '';

        $sql = "SELECT i.id as instrument_id, i.input_ticker, i.display_ticker, i.name,
                i.is_portfolio, i.active, i.wkn, i.isin, i.region
            FROM instrument i
            LEFT JOIN trade_campaign tc ON i.id = tc.instrument_id
            WHERE i.is_portfolio = 1 AND tc.id IS NULL{$filter}
            ORDER BY i.input_ticker";

        return $this->connection->fetchAllAssociative($sql);
    }

    private function findLatestSepaSnapshot(int $instrumentId): ?array
    {
        $sql = "SELECT id, as_of_date, traffic_light, total_score
            FROM instrument_sepa_snapshot
            WHERE instrument_id = ?
            ORDER BY as_of_date DESC, id DESC
            LIMIT 1";

        $result = $this->connection->fetchAssociative($sql, [$instrumentId]);
        return $result ?: null;
    }

    private function findLatestEpaSnapshot(int $instrumentId): ?array
    {
        $sql = "SELECT id, as_of_date, action, total_score
            FROM instrument_epa_snapshot
            WHERE instrument_id = ?
            ORDER BY as_of_date DESC, id DESC
            LIMIT 1";

        $result = $this->connection->fetchAssociative($sql, [$instrumentId]);
        return $result ?: null;
    }

    private function findLatestRunItem(int $instrumentId): ?array
    {
        $sql = "SELECT pri.id, pri.decision, pri.merged_score
            FROM pipeline_run_item pri
            JOIN pipeline_run pr ON pri.pipeline_run_id = pr.id
            WHERE pri.instrument_id = ?
            ORDER BY pr.started_at DESC, pr.id DESC
            LIMIT 1";

        $result = $this->connection->fetchAssociative($sql, [$instrumentId]);
        return $result ?: null;
    }

    private function buildSeedPlan(array $instrument): array
    {
        $instrumentId = (int) $instrument['instrument_id'];
        $sepaSnapshot = $this->findLatestSepaSnapshot($instrumentId);
        $epaSnapshot = $this->findLatestEpaSnapshot($instrumentId);
        $runItem = $this->findLatestRunItem($instrumentId);

        $missingData = [];
        if (empty($instrument['quantity'])) {
            $missingData[] = 'quantity';
        }
        if (empty($instrument['avg_entry_price'])) {
            $missingData[] = 'avg_entry_price';
        }
        if (empty($instrument['opened_at'])) {
            $missingData[] = 'opened_at';
        }

        return [
            'instrument' => [
                'id' => $instrumentId,
                'input_ticker' => $instrument['input_ticker'],
                'display_ticker' => $instrument['display_ticker'],
                'name' => $instrument['name'],
                'is_portfolio' => (bool) $instrument['is_portfolio'],
                'active' => (bool) $instrument['active'],
                'wkn' => $instrument['wkn'],
                'isin' => $instrument['isin'],
                'region' => $instrument['region'],
            ],
            'latest_snapshots' => [
                'sepa' => $sepaSnapshot ? [
                    'id' => $sepaSnapshot['id'],
                    'as_of_date' => $sepaSnapshot['as_of_date'],
                    'traffic_light' => $sepaSnapshot['traffic_light'],
                    'total_score' => $sepaSnapshot['total_score'],
                ] : null,
                'epa' => $epaSnapshot ? [
                    'id' => $epaSnapshot['id'],
                    'as_of_date' => $epaSnapshot['as_of_date'],
                    'action' => $epaSnapshot['action'],
                    'total_score' => $epaSnapshot['total_score'],
                ] : null,
            ],
            'latest_run_item' => $runItem ? [
                'id' => $runItem['id'],
                'decision' => $runItem['decision'],
                'merged_score' => $runItem['merged_score'],
            ] : null,
            'missing_seed_data' => $missingData,
            'proposed_migration_status' => 'manual_seed',
            'reason' => 'Legacy portfolio instrument has no trade_campaign/trade_event history. Quantity, entry price and opened_at cannot be inferred safely.',
            'proposed_campaign' => [
                'instrument_id' => $instrumentId,
                'trade_type' => 'REQUIRES_MANUAL_DECISION',
                'state' => 'open',
                'entry_thesis' => 'Legacy portfolio seed - manual review required',
                'invalidation_rule' => null,
                'total_quantity' => null,
                'open_quantity' => null,
                'avg_entry_price' => null,
                'opened_at' => 'MANUAL_REQUIRED',
                'entry_macro_snapshot_id' => null,
            ],
            'proposed_event' => [
                'event_type' => 'migration_seed',
                'event_price' => null,
                'quantity' => null,
                'fees' => 0,
                'currency' => 'EUR',
                'event_timestamp' => 'MANUAL_REQUIRED',
                'buy_signal_snapshot_id' => null,
                'candidate_sepa_snapshot_id' => $sepaSnapshot ? $sepaSnapshot['id'] : null,
                'candidate_epa_snapshot_id' => $epaSnapshot ? $epaSnapshot['id'] : null,
                'macro_snapshot_id' => null,
                'scoring_version' => self::SYSTEM_VERSIONS['scoring_version'],
                'policy_version' => self::SYSTEM_VERSIONS['policy_version'],
                'model_version' => self::SYSTEM_VERSIONS['model_version'],
                'macro_version' => self::SYSTEM_VERSIONS['macro_version'],
                'event_notes' => 'Legacy migration seed plan only - not applied',
            ],
            'warnings' => [
                'Snapshot IDs are marked as candidates only because event_timestamp is unknown.',
                'v0.4 Truth Layer requires: snapshot references must point to last completed snapshot BEFORE event_timestamp.',
                'Without known entry timestamp, automatic snapshot assignment would violate v0.4 rules.',
            ],
        ];
    }

    private function renderTextOutput(SymfonyStyle $io, array $plans): void
    {
        $io->title('v0.4 Legacy Seed Plan');
        $io->text(sprintf('Found %d legacy portfolio instrument(s) without trade campaigns.', count($plans)));
        $io->newLine();

        foreach ($plans as $plan) {
            $this->renderInstrumentPlan($io, $plan);
        }

        $io->section('System Versions (for proposed events)');
        foreach (self::SYSTEM_VERSIONS as $key => $value) {
            $io->text(sprintf('  %s: %s', $key, $value));
        }

        $io->newLine(2);
        $io->warning('This is a READ-ONLY plan. No data has been modified.');
        $io->note('To apply seeds, manual intervention is required with verified quantity, price, and timestamp.');
    }

    private function renderInstrumentPlan(SymfonyStyle $io, array $plan): void
    {
        $inst = $plan['instrument'];

        $io->section(sprintf('Instrument: %s (ID: %d)', $inst['input_ticker'], $inst['id']));

        // Basic info
        $io->text('<info>Basic Info:</info>');
        $infoRows = [
            ['Display Ticker', $inst['display_ticker'] ?? '-'],
            ['Name', $inst['name'] ?? '-'],
            ['WKN', $inst['wkn'] ?? '-'],
            ['ISIN', $inst['isin'] ?? '-'],
            ['Region', $inst['region'] ?? '-'],
            ['Active', $inst['active'] ? 'Yes' : 'No'],
        ];
        foreach ($infoRows as [$label, $value]) {
            $io->text(sprintf('  %-15s %s', $label . ':', $value));
        }

        // Latest Snapshots
        $io->newLine();
        $io->text('<info>Latest Available Snapshots:</info>');
        if ($plan['latest_snapshots']['sepa']) {
            $sepa = $plan['latest_snapshots']['sepa'];
            $io->text(sprintf('  SEPA:  ID=%d, Date=%s, Light=%s, Score=%s',
                $sepa['id'], $sepa['as_of_date'], $sepa['traffic_light'], $sepa['total_score']));
        } else {
            $io->text('  SEPA:  <comment>None available</comment>');
        }
        if ($plan['latest_snapshots']['epa']) {
            $epa = $plan['latest_snapshots']['epa'];
            $io->text(sprintf('  EPA:   ID=%d, Date=%s, Action=%s, Score=%s',
                $epa['id'], $epa['as_of_date'], $epa['action'], $epa['total_score']));
        } else {
            $io->text('  EPA:   <comment>None available</comment>');
        }

        // Latest Run Item
        if ($plan['latest_run_item']) {
            $run = $plan['latest_run_item'];
            $io->text(sprintf('  Run:   ID=%d, Decision=%s, Merged=%s',
                $run['id'], $run['decision'], $run['merged_score'] ?? '-'));
        } else {
            $io->text('  Run:   <comment>None available</comment>');
        }

        // Missing Data
        $io->newLine();
        if (!empty($plan['missing_seed_data'])) {
            $io->text('<comment>Missing Seed Data:</comment> ' . implode(', ', $plan['missing_seed_data']));
        } else {
            $io->text('<info>Missing Seed Data:</info> None (all required fields present)');
        }

        // Proposed Campaign
        $io->newLine();
        $io->text('<info>Proposed Campaign:</info>');
        $campaign = $plan['proposed_campaign'];
        $io->text(sprintf('  State:          %s', $campaign['state']));
        $io->text(sprintf('  Trade Type:     <comment>%s</comment> (manual decision required)', $campaign['trade_type']));
        $io->text(sprintf('  Total Qty:      <comment>%s</comment>', $campaign['total_quantity'] ?? 'MANUAL_REQUIRED'));
        $io->text(sprintf('  Open Qty:       <comment>%s</comment>', $campaign['open_quantity'] ?? 'MANUAL_REQUIRED'));
        $io->text(sprintf('  Avg Entry:      <comment>%s</comment>', $campaign['avg_entry_price'] ?? 'MANUAL_REQUIRED'));
        $io->text(sprintf('  Opened At:      <comment>%s</comment>', $campaign['opened_at']));
        $io->text(sprintf('  Thesis:         %s', $campaign['entry_thesis']));

        // Proposed Event
        $io->newLine();
        $io->text('<info>Proposed Event:</info>');
        $event = $plan['proposed_event'];
        $io->text(sprintf('  Type:           %s', $event['event_type']));
        $io->text(sprintf('  Price:          <comment>%s</comment>', $event['event_price'] ?? 'MANUAL_REQUIRED'));
        $io->text(sprintf('  Quantity:       <comment>%s</comment>', $event['quantity'] ?? 'MANUAL_REQUIRED'));
        $io->text(sprintf('  Timestamp:      <comment>%s</comment>', $event['event_timestamp']));
        $io->text(sprintf('  Fees:           %s %s', $event['fees'], $event['currency']));

        // Candidate Snapshots
        $io->newLine();
        $io->text('<comment>Candidate Snapshots (NOT automatically assigned):</comment>');
        if ($event['candidate_sepa_snapshot_id']) {
            $io->text(sprintf('  Candidate SEPA: %d (only valid if event_timestamp >= snapshot_date)', $event['candidate_sepa_snapshot_id']));
        } else {
            $io->text('  Candidate SEPA: None');
        }
        if ($event['candidate_epa_snapshot_id']) {
            $io->text(sprintf('  Candidate EPA:  %d (only valid if event_timestamp >= snapshot_date)', $event['candidate_epa_snapshot_id']));
        } else {
            $io->text('  Candidate EPA:  None');
        }

        // Warnings
        $io->newLine();
        $io->text('<comment>Warnings:</comment>');
        foreach ($plan['warnings'] as $warning) {
            $io->text('  ⚠ ' . $warning);
        }

        $io->newLine(2);
        $io->writeln(str_repeat('-', 60));
        $io->newLine();
    }
}