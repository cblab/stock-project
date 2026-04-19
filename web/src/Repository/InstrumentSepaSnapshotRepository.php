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
}
