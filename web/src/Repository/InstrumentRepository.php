<?php

namespace App\Repository;

use App\Entity\Instrument;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Instrument>
 */
class InstrumentRepository extends ServiceEntityRepository
{
    private const SORT_FIELDS = [
        'ticker' => 'i.input_ticker',
        'provider' => 'i.provider_ticker',
        'name' => 'i.name',
        'asset' => 'i.asset_class',
        'region' => 'i.region',
        'sellSignal' => 'e.action',
        'buyDecision' => 'pri.decision',
    ];

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Instrument::class);
    }

    /** @return Instrument[] */
    public function findActivePortfolio(): array
    {
        return $this->findBy(['active' => true, 'isPortfolio' => true], ['inputTicker' => 'ASC']);
    }

    /** @return array<int, array<string, mixed>> */
    public function findPortfolioInstruments(string $sort = 'ticker', string $direction = 'asc'): array
    {
        return $this->findInstrumentList(true, $sort, $direction);
    }

    /** @return array<int, array<string, mixed>> */
    public function findWatchlistInstruments(string $sort = 'ticker', string $direction = 'asc'): array
    {
        return $this->findInstrumentList(false, $sort, $direction);
    }

    /** @return Instrument[] */
    public function findInactiveInstruments(): array
    {
        return $this->findBy(['active' => false], ['inputTicker' => 'ASC']);
    }

    /** @return array<int, array<string, mixed>> */
    private function findInstrumentList(bool $portfolio, string $sort, string $direction): array
    {
        $allowedSorts = $portfolio
            ? ['ticker', 'provider', 'name', 'asset', 'region', 'sellSignal']
            : ['ticker', 'provider', 'name', 'asset', 'region', 'buyDecision'];
        $sort = in_array($sort, $allowedSorts, true) ? $sort : 'ticker';
        $direction = strtolower($direction) === 'desc' ? 'desc' : 'asc';
        $sortExpression = self::SORT_FIELDS[$sort];
        $sqlDirection = strtoupper($direction);
        $rows = $this->getEntityManager()->getConnection()->fetchAllAssociative(
            <<<SQL
            SELECT
                i.id,
                i.input_ticker,
                i.provider_ticker,
                i.display_ticker,
                i.name,
                i.wkn,
                i.asset_class,
                i.region,
                i.active,
                i.is_portfolio,
                pri.decision AS buy_decision,
                e.action AS sell_signal
            FROM instrument i
            LEFT JOIN pipeline_run_item pri ON pri.id = (
                SELECT pri2.id
                FROM pipeline_run_item pri2
                INNER JOIN pipeline_run pr2 ON pr2.id = pri2.pipeline_run_id
                WHERE pri2.instrument_id = i.id
                ORDER BY COALESCE(pr2.finished_at, pr2.started_at, pr2.created_at) DESC, pr2.id DESC, pri2.id DESC
                LIMIT 1
            )
            LEFT JOIN instrument_epa_snapshot e ON e.id = (
                SELECT e2.id
                FROM instrument_epa_snapshot e2
                WHERE e2.instrument_id = i.id
                ORDER BY e2.as_of_date DESC, e2.id DESC
                LIMIT 1
            )
            WHERE i.active = 1 AND i.is_portfolio = ?
            ORDER BY ($sortExpression IS NULL) ASC, $sortExpression $sqlDirection, i.input_ticker ASC
            SQL,
            [$portfolio ? 1 : 0],
        );

        return array_map(static function (array $row): array {
            $row['active'] = (bool) $row['active'];
            $row['is_portfolio'] = (bool) $row['is_portfolio'];

            return $row;
        }, $rows);
    }
}
