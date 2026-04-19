<?php

namespace App\Repository;

use App\Entity\Instrument;
use App\Entity\PipelineRun;
use App\Entity\PipelineRunItem;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PipelineRunItem>
 */
class PipelineRunItemRepository extends ServiceEntityRepository
{
    private const SORT_FIELDS = [
        'inputTicker' => 'instrument.inputTicker',
        'providerTicker' => 'instrument.providerTicker',
        'assetClass' => 'instrument.assetClass',
        'region' => 'instrument.region',
        'sentimentMode' => 'item.sentimentMode',
        'kronosNormalizedScore' => 'item.kronosNormalizedScore',
        'sentimentNormalizedScore' => 'item.sentimentNormalizedScore',
        'mergedScore' => 'item.mergedScore',
        'decision' => 'item.decision',
        'marketDataStatus' => 'item.marketDataStatus',
        'newsStatus' => 'item.newsStatus',
    ];

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PipelineRunItem::class);
    }

    /** @return PipelineRunItem[] */
    public function findForRunSorted(PipelineRun $run, string $sort = 'mergedScore', string $direction = 'desc'): array
    {
        $sort = self::normalizeSort($sort);
        $direction = self::normalizeDirection($direction);

        return $this->createQueryBuilder('item')
            ->join('item.instrument', 'instrument')
            ->addSelect('instrument')
            ->andWhere('item.pipelineRun = :run')
            ->setParameter('run', $run)
            ->orderBy(self::SORT_FIELDS[$sort], strtoupper($direction))
            ->addOrderBy('instrument.inputTicker', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findLatestForInstrument(Instrument $instrument): ?PipelineRunItem
    {
        return $this->createQueryBuilder('item')
            ->join('item.pipelineRun', 'run')
            ->addSelect('run')
            ->addSelect('COALESCE(run.startedAt, run.createdAt) AS HIDDEN runSortAt')
            ->andWhere('item.instrument = :instrument')
            ->setParameter('instrument', $instrument)
            ->orderBy('runSortAt', 'DESC')
            ->addOrderBy('run.id', 'DESC')
            ->addOrderBy('item.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public static function normalizeSort(string $sort): string
    {
        return array_key_exists($sort, self::SORT_FIELDS) ? $sort : 'mergedScore';
    }

    public static function normalizeDirection(string $direction): string
    {
        return strtolower($direction) === 'asc' ? 'asc' : 'desc';
    }
}
