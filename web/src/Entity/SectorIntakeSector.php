<?php

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'sector_intake_sector')]
#[ORM\Index(columns: ['run_id'], name: 'IDX_SECTOR_INTAKE_SECTOR_RUN')]
#[ORM\Index(columns: ['sector_rank'], name: 'idx_sector_intake_sector_rank')]
class SectorIntakeSector
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: SectorIntakeRun::class)]
    #[ORM\JoinColumn(name: 'run_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private SectorIntakeRun $run;

    #[ORM\Column(length: 64)]
    private string $sectorKey = '';

    #[ORM\Column(length: 128)]
    private string $sectorLabel = '';

    #[ORM\Column(length: 32)]
    private string $proxyTicker = '';

    #[ORM\Column]
    private int $sectorRank = 0;

    #[ORM\Column]
    private float $sectorScore = 0.0;

    #[ORM\Column(name: 'return_1m_pct')]
    private float $return1mPct = 0.0;

    #[ORM\Column(name: 'return_3m_pct')]
    private float $return3mPct = 0.0;

    #[ORM\Column(name: 'relative_1m_pct')]
    private float $relative1mPct = 0.0;

    #[ORM\Column(name: 'relative_3m_pct')]
    private float $relative3mPct = 0.0;

    #[ORM\Column(type: Types::JSON)]
    private array $detailJson = [];

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;
}
