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
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Instrument::class);
    }

    /** @return Instrument[] */
    public function findActivePortfolio(): array
    {
        return $this->findBy(['active' => true, 'isPortfolio' => true], ['inputTicker' => 'ASC']);
    }

    /** @return Instrument[] */
    public function findPortfolioInstruments(): array
    {
        return $this->findBy(['isPortfolio' => true], ['inputTicker' => 'ASC']);
    }

    /** @return Instrument[] */
    public function findWatchlistInstruments(): array
    {
        return $this->findBy(['isPortfolio' => false], ['inputTicker' => 'ASC']);
    }
}
