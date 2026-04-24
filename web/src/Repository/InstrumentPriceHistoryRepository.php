<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\InstrumentPriceHistory;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<InstrumentPriceHistory>
 */
class InstrumentPriceHistoryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, InstrumentPriceHistory::class);
    }

    /**
     * Find price history entry for a specific instrument and date.
     */
    public function findByInstrumentAndDate(int $instrumentId, \DateTimeInterface $date): ?InstrumentPriceHistory
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.instrument = :instrumentId')
            ->andWhere('p.priceDate = :date')
            ->setParameter('instrumentId', $instrumentId)
            ->setParameter('date', $date)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Find all price history entries for an instrument within a date range.
     *
     * @return InstrumentPriceHistory[]
     */
    public function findByInstrumentAndDateRange(int $instrumentId, \DateTimeInterface $startDate, \DateTimeInterface $endDate): array
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.instrument = :instrumentId')
            ->andWhere('p.priceDate >= :startDate')
            ->andWhere('p.priceDate <= :endDate')
            ->setParameter('instrumentId', $instrumentId)
            ->setParameter('startDate', $startDate)
            ->setParameter('endDate', $endDate)
            ->orderBy('p.priceDate', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Get the earliest available price date for an instrument.
     */
    public function findEarliestDateForInstrument(int $instrumentId): ?\DateTimeInterface
    {
        $result = $this->createQueryBuilder('p')
            ->select('MIN(p.priceDate)')
            ->andWhere('p.instrument = :instrumentId')
            ->setParameter('instrumentId', $instrumentId)
            ->getQuery()
            ->getSingleScalarResult();

        return $result ? new \DateTimeImmutable($result) : null;
    }

    /**
     * Get the latest available price date for an instrument.
     */
    public function findLatestDateForInstrument(int $instrumentId): ?\DateTimeInterface
    {
        $result = $this->createQueryBuilder('p')
            ->select('MAX(p.priceDate)')
            ->andWhere('p.instrument = :instrumentId')
            ->setParameter('instrumentId', $instrumentId)
            ->getQuery()
            ->getSingleScalarResult();

        return $result ? new \DateTimeImmutable($result) : null;
    }

    /**
     * Count price history entries for an instrument.
     */
    public function countByInstrument(int $instrumentId): int
    {
        return (int) $this->createQueryBuilder('p')
            ->select('COUNT(p.id)')
            ->andWhere('p.instrument = :instrumentId')
            ->setParameter('instrumentId', $instrumentId)
            ->getQuery()
            ->getSingleScalarResult();
    }
}