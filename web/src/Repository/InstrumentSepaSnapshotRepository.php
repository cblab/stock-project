<?php

namespace App\Repository;

use App\Entity\Instrument;
use App\Entity\InstrumentSepaSnapshot;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<InstrumentSepaSnapshot>
 */
class InstrumentSepaSnapshotRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, InstrumentSepaSnapshot::class);
    }

    public function findLatestForInstrument(Instrument $instrument): ?InstrumentSepaSnapshot
    {
        return $this->createQueryBuilder('snapshot')
            ->andWhere('snapshot.instrument = :instrument')
            ->setParameter('instrument', $instrument)
            ->orderBy('snapshot.asOfDate', 'DESC')
            ->addOrderBy('snapshot.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * @param int[] $instrumentIds
     * @return array<int, InstrumentSepaSnapshot>
     */
    public function findLatestIndexedByInstrumentIds(array $instrumentIds): array
    {
        $instrumentIds = array_values(array_unique(array_filter(array_map('intval', $instrumentIds))));
        if ($instrumentIds === []) {
            return [];
        }

        $rows = $this->createQueryBuilder('snapshot')
            ->join('snapshot.instrument', 'instrument')
            ->addSelect('instrument')
            ->andWhere('instrument.id IN (:instrumentIds)')
            ->setParameter('instrumentIds', $instrumentIds)
            ->orderBy('snapshot.asOfDate', 'DESC')
            ->addOrderBy('snapshot.id', 'DESC')
            ->getQuery()
            ->getResult();

        $latest = [];
        foreach ($rows as $snapshot) {
            $instrumentId = $snapshot->getInstrument()->getId();
            if ($instrumentId !== null && !isset($latest[$instrumentId])) {
                $latest[$instrumentId] = $snapshot;
            }
        }

        return $latest;
    }
}
