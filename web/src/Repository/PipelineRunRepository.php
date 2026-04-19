<?php

namespace App\Repository;

use App\Entity\PipelineRun;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PipelineRun>
 */
class PipelineRunRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PipelineRun::class);
    }

    /**
     * @return PipelineRun[]
     */
    public function findLatest(int $limit = 50): array
    {
        return $this->createQueryBuilder('run')
            ->orderBy('run.createdAt', 'DESC')
            ->addOrderBy('run.id', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
