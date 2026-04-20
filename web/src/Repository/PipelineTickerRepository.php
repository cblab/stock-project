<?php

namespace App\Repository;

use App\Entity\PipelineRun;
use App\Entity\PipelineTicker;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Legacy repository for pre-instrument pipeline_ticker rows.
 *
 * New code should read PipelineRunItemRepository unless it intentionally needs
 * historic imported rows from the old table.
 *
 * @extends ServiceEntityRepository<PipelineTicker>
 */
class PipelineTickerRepository extends ServiceEntityRepository
{
    private const SORT_FIELDS = [
        'inputTicker' => 'inputTicker',
        'providerTicker' => 'providerTicker',
        'assetClass' => 'assetClass',
        'region' => 'region',
        'sentimentMode' => 'sentimentMode',
        'kronosNormalizedScore' => 'kronosNormalizedScore',
        'sentimentNormalizedScore' => 'sentimentNormalizedScore',
        'mergedScore' => 'mergedScore',
        'decision' => 'decision',
        'marketDataStatus' => 'marketDataStatus',
        'newsStatus' => 'newsStatus',
    ];

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PipelineTicker::class);
    }

    /**
     * @return PipelineTicker[]
     */
    public function findForRunSorted(PipelineRun $run, string $sort = 'mergedScore', string $direction = 'desc'): array
    {
        $sort = self::normalizeSort($sort);
        $direction = self::normalizeDirection($direction);

        return $this->createQueryBuilder('ticker')
            ->andWhere('ticker.pipelineRun = :run')
            ->setParameter('run', $run)
            ->orderBy('ticker.'.$sort, strtoupper($direction))
            ->addOrderBy('ticker.inputTicker', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public static function normalizeSort(string $sort): string
    {
        return self::SORT_FIELDS[$sort] ?? 'mergedScore';
    }

    public static function normalizeDirection(string $direction): string
    {
        return strtolower($direction) === 'asc' ? 'asc' : 'desc';
    }

    /**
     * @return array<string, string>
     */
    public static function sortableFields(): array
    {
        return self::SORT_FIELDS;
    }
}
