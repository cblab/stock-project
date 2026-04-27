<?php

namespace App\Command;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:trade:integrity-report', description: 'Report integrity issues in v0.4 trade layer.')]
class TradeIntegrityReportCommand extends Command
{
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

        $issues = [];
        $issues[] = $this->checkPortfolioWithoutCampaigns($instrumentFilter);
        $issues[] = $this->checkCampaignsWithoutEvents($instrumentFilter);
        $issues[] = $this->checkMultipleOpenCampaigns($instrumentFilter);
        $issues[] = $this->checkOpenCampaignsWithInvalidQuantity($instrumentFilter);
        $issues[] = $this->checkCampaignsWithMissingCoreData($instrumentFilter);
        $issues[] = $this->checkClosedCampaignsWithOpenQuantity($instrumentFilter);
        $issues[] = $this->checkEntryEventsWithMissingData($instrumentFilter);
        $issues[] = $this->checkExitEventsWithMissingPrice($instrumentFilter);
        $issues[] = $this->checkEventsWithInvalidSnapshotRefs($instrumentFilter);
        $issues[] = $this->checkEventsWithMissingVersions($instrumentFilter);

        $totalIssues = array_sum(array_column($issues, 'count'));

        if ($format === 'json') {
            $output->writeln(json_encode([
                'total_issues' => $totalIssues,
                'checks' => $issues,
            ], JSON_PRETTY_PRINT));
            return $totalIssues > 0 ? Command::FAILURE : Command::SUCCESS;
        }

        $io->title('v0.4 Trade Layer Integrity Report');

        if ($instrumentFilter) {
            $io->note(sprintf('Filtered by instrument ID: %d', $instrumentFilter));
        }

        foreach ($issues as $check) {
            $this->renderCheck($io, $check);
        }

        $io->section('Summary');
        if ($totalIssues === 0) {
            $io->success('No integrity issues found.');
            return Command::SUCCESS;
        }

        $io->warning(sprintf('Found %d integrity issue(s).', $totalIssues));
        return Command::FAILURE;
    }

    private function renderCheck(SymfonyStyle $io, array $check): void
    {
        if ($check['count'] === 0) {
            return;
        }

        $io->section(sprintf('%s (%d)', $check['title'], $check['count']));
        $io->text($check['description']);

        if (!empty($check['rows'])) {
            $io->table($check['headers'], $check['rows']);
        }
    }

    private function buildInstrumentFilter(?int $instrumentId, string $tableAlias = 'tc'): string
    {
        if ($instrumentId === null) {
            return '';
        }
        return sprintf(' AND %s.instrument_id = %d', $tableAlias, $instrumentId);
    }

    private function checkPortfolioWithoutCampaigns(?int $instrumentId): array
    {
        $sql = sprintf(
            "SELECT i.id as instrument_id, i.input_ticker
            FROM instrument i
            LEFT JOIN trade_campaign tc ON i.id = tc.instrument_id
            WHERE i.is_portfolio = 1 AND tc.id IS NULL%s",
            $instrumentId ? " AND i.id = {$instrumentId}" : ''
        );

        $rows = $this->connection->fetchAllAssociative($sql);

        return [
            'title' => '1. Portfolio Instruments without Trade Campaigns',
            'description' => 'Portfolio instruments that have no associated trade campaigns.',
            'count' => count($rows),
            'headers' => ['Instrument ID', 'Ticker'],
            'rows' => array_map(fn($r) => [$r['instrument_id'], $r['input_ticker']], $rows),
        ];
    }

    private function checkCampaignsWithoutEvents(?int $instrumentId): array
    {
        $filter = $this->buildInstrumentFilter($instrumentId, 'tc');
        $sql = "SELECT tc.id as campaign_id, tc.instrument_id, tc.state, tc.opened_at
            FROM trade_campaign tc
            LEFT JOIN trade_event te ON tc.id = te.trade_campaign_id
            WHERE te.id IS NULL{$filter}";

        $rows = $this->connection->fetchAllAssociative($sql);

        return [
            'title' => '2. Trade Campaigns without Events',
            'description' => 'Campaigns that have no trade events recorded.',
            'count' => count($rows),
            'headers' => ['Campaign ID', 'Instrument ID', 'State', 'Opened At'],
            'rows' => array_map(fn($r) => [$r['campaign_id'], $r['instrument_id'], $r['state'], $r['opened_at']], $rows),
        ];
    }

    private function checkMultipleOpenCampaigns(?int $instrumentId): array
    {
        $filter = $instrumentId ? " AND i.id = {$instrumentId}" : '';
        $sql = "SELECT i.id as instrument_id, i.input_ticker, COUNT(tc.id) as open_count
            FROM instrument i
            JOIN trade_campaign tc ON i.id = tc.instrument_id
            WHERE tc.state IN ('open', 'trimmed', 'paused'){$filter}
            GROUP BY i.id, i.input_ticker
            HAVING open_count > 1";

        $rows = $this->connection->fetchAllAssociative($sql);

        return [
            'title' => '3. Instruments with Multiple Open Campaigns',
            'description' => 'Instruments having more than one open trade campaign.',
            'count' => count($rows),
            'headers' => ['Instrument ID', 'Ticker', 'Open Campaigns'],
            'rows' => array_map(fn($r) => [$r['instrument_id'], $r['input_ticker'], $r['open_count']], $rows),
        ];
    }

    private function checkOpenCampaignsWithInvalidQuantity(?int $instrumentId): array
    {
        $filter = $this->buildInstrumentFilter($instrumentId, 'tc');
        $sql = "SELECT tc.id as campaign_id, tc.instrument_id, tc.state, tc.open_quantity
            FROM trade_campaign tc
            WHERE tc.state IN ('open', 'trimmed', 'paused')
            AND (tc.open_quantity IS NULL OR tc.open_quantity <= 0){$filter}";

        $rows = $this->connection->fetchAllAssociative($sql);

        return [
            'title' => '4. Open Campaigns with Invalid Open Quantity',
            'description' => 'Open campaigns where open_quantity is NULL or <= 0.',
            'count' => count($rows),
            'headers' => ['Campaign ID', 'Instrument ID', 'State', 'Open Quantity'],
            'rows' => array_map(fn($r) => [$r['campaign_id'], $r['instrument_id'], $r['state'], $r['open_quantity'] ?? 'NULL'], $rows),
        ];
    }

    private function checkCampaignsWithMissingCoreData(?int $instrumentId): array
    {
        $filter = $this->buildInstrumentFilter($instrumentId, 'tc');
        $sql = "SELECT tc.id as campaign_id, tc.instrument_id, tc.total_quantity, tc.avg_entry_price
            FROM trade_campaign tc
            WHERE (tc.total_quantity IS NULL OR tc.avg_entry_price IS NULL){$filter}";

        $rows = $this->connection->fetchAllAssociative($sql);

        return [
            'title' => '5. Campaigns with Missing Core Data',
            'description' => 'Campaigns missing total_quantity or avg_entry_price.',
            'count' => count($rows),
            'headers' => ['Campaign ID', 'Instrument ID', 'Total Qty', 'Avg Entry Price'],
            'rows' => array_map(fn($r) => [
                $r['campaign_id'],
                $r['instrument_id'],
                $r['total_quantity'] ?? 'NULL',
                $r['avg_entry_price'] ?? 'NULL'
            ], $rows),
        ];
    }

    private function checkClosedCampaignsWithOpenQuantity(?int $instrumentId): array
    {
        $filter = $this->buildInstrumentFilter($instrumentId, 'tc');
        $sql = "SELECT tc.id as campaign_id, tc.instrument_id, tc.state, tc.open_quantity
            FROM trade_campaign tc
            WHERE (tc.state LIKE 'closed%' OR tc.state = 'returned_to_watchlist')
            AND tc.open_quantity > 0{$filter}";

        $rows = $this->connection->fetchAllAssociative($sql);

        return [
            'title' => '6. Closed/Returned Campaigns with Open Quantity',
            'description' => 'Closed or returned campaigns still having open_quantity > 0.',
            'count' => count($rows),
            'headers' => ['Campaign ID', 'Instrument ID', 'State', 'Open Quantity'],
            'rows' => array_map(fn($r) => [$r['campaign_id'], $r['instrument_id'], $r['state'], $r['open_quantity']], $rows),
        ];
    }

    private function checkEntryEventsWithMissingData(?int $instrumentId): array
    {
        $filter = $this->buildInstrumentFilter($instrumentId, 'tc');
        $sql = "SELECT te.id as event_id, te.trade_campaign_id, tc.instrument_id, te.event_type, te.quantity, te.event_price
            FROM trade_event te
            JOIN trade_campaign tc ON te.trade_campaign_id = tc.id
            WHERE te.event_type IN ('entry', 'add', 'trim')
            AND (te.quantity IS NULL OR te.event_price IS NULL){$filter}";

        $rows = $this->connection->fetchAllAssociative($sql);

        return [
            'title' => '7. Entry/Add/Trim Events with Missing Data',
            'description' => 'Entry, add, or trim events missing quantity or event_price.',
            'count' => count($rows),
            'headers' => ['Event ID', 'Campaign ID', 'Instrument ID', 'Type', 'Quantity', 'Price'],
            'rows' => array_map(fn($r) => [
                $r['event_id'],
                $r['trade_campaign_id'],
                $r['instrument_id'],
                $r['event_type'],
                $r['quantity'] ?? 'NULL',
                $r['event_price'] ?? 'NULL'
            ], $rows),
        ];
    }

    private function checkExitEventsWithMissingPrice(?int $instrumentId): array
    {
        $filter = $this->buildInstrumentFilter($instrumentId, 'tc');
        $sql = "SELECT te.id as event_id, te.trade_campaign_id, tc.instrument_id, te.event_type, te.event_price
            FROM trade_event te
            JOIN trade_campaign tc ON te.trade_campaign_id = tc.id
            WHERE te.event_type IN ('hard_exit', 'return_to_watchlist')
            AND te.event_price IS NULL{$filter}";

        $rows = $this->connection->fetchAllAssociative($sql);

        return [
            'title' => '8. Exit Events with Missing Price',
            'description' => 'Hard exit or return_to_watchlist events missing event_price.',
            'count' => count($rows),
            'headers' => ['Event ID', 'Campaign ID', 'Instrument ID', 'Type', 'Price'],
            'rows' => array_map(fn($r) => [
                $r['event_id'],
                $r['trade_campaign_id'],
                $r['instrument_id'],
                $r['event_type'],
                $r['event_price'] ?? 'NULL'
            ], $rows),
        ];
    }

    private function checkEventsWithInvalidSnapshotRefs(?int $instrumentId): array
    {
        $filter = $this->buildInstrumentFilter($instrumentId, 'tc');
        $sql = "SELECT te.id as event_id, te.trade_campaign_id, tc.instrument_id, te.event_type,
                te.buy_signal_snapshot_id, te.sepa_snapshot_id, te.epa_snapshot_id, te.macro_snapshot_id
            FROM trade_event te
            JOIN trade_campaign tc ON te.trade_campaign_id = tc.id
            LEFT JOIN instrument_buy_signal_snapshot bss ON te.buy_signal_snapshot_id = bss.id
            LEFT JOIN instrument_sepa_snapshot ss ON te.sepa_snapshot_id = ss.id
            LEFT JOIN instrument_epa_snapshot es ON te.epa_snapshot_id = es.id
            WHERE (
                (te.buy_signal_snapshot_id IS NOT NULL AND bss.id IS NULL)
                OR (te.sepa_snapshot_id IS NOT NULL AND ss.id IS NULL)
                OR (te.epa_snapshot_id IS NOT NULL AND es.id IS NULL)
            ){$filter}";

        $rows = $this->connection->fetchAllAssociative($sql);

        return [
            'title' => '9. Events with Invalid Snapshot References',
            'description' => 'Events referencing non-existent snapshot IDs (buy_signal, SEPA, EPA).',
            'count' => count($rows),
            'headers' => ['Event ID', 'Campaign ID', 'Instrument ID', 'Type', 'BS Snap', 'SEPA Snap', 'EPA Snap'],
            'rows' => array_map(fn($r) => [
                $r['event_id'],
                $r['trade_campaign_id'],
                $r['instrument_id'],
                $r['event_type'],
                $r['buy_signal_snapshot_id'] ?? '-',
                $r['sepa_snapshot_id'] ?? '-',
                $r['epa_snapshot_id'] ?? '-'
            ], $rows),
        ];
    }

    private function checkEventsWithMissingVersions(?int $instrumentId): array
    {
        $filter = $this->buildInstrumentFilter($instrumentId, 'tc');
        $sql = "SELECT te.id as event_id, te.trade_campaign_id, tc.instrument_id, te.event_type,
                te.scoring_version, te.policy_version, te.model_version, te.macro_version
            FROM trade_event te
            JOIN trade_campaign tc ON te.trade_campaign_id = tc.id
            WHERE (
                te.scoring_version IS NULL
                OR te.policy_version IS NULL
                OR te.model_version IS NULL
                OR te.macro_version IS NULL
            ){$filter}";

        $rows = $this->connection->fetchAllAssociative($sql);

        return [
            'title' => '10. Events with Missing Version Strings',
            'description' => 'Events with NULL in scoring_version, policy_version, model_version, or macro_version.',
            'count' => count($rows),
            'headers' => ['Event ID', 'Campaign ID', 'Instrument ID', 'Type', 'Scoring', 'Policy', 'Model', 'Macro'],
            'rows' => array_map(fn($r) => [
                $r['event_id'],
                $r['trade_campaign_id'],
                $r['instrument_id'],
                $r['event_type'],
                $r['scoring_version'] ?? 'NULL',
                $r['policy_version'] ?? 'NULL',
                $r['model_version'] ?? 'NULL',
                $r['macro_version'] ?? 'NULL'
            ], $rows),
        ];
    }
}
