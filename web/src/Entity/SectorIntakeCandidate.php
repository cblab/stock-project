<?php

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'sector_intake_candidate')]
#[ORM\Index(columns: ['run_id'], name: 'IDX_SECTOR_INTAKE_CANDIDATE_RUN')]
#[ORM\Index(columns: ['status'], name: 'idx_sector_intake_candidate_status')]
#[ORM\Index(columns: ['added_to_watchlist'], name: 'idx_sector_intake_candidate_added')]
#[ORM\Index(columns: ['ticker', 'created_at', 'id'], name: 'idx_sector_intake_candidate_ticker_created_id')]
class SectorIntakeCandidate
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: SectorIntakeRun::class)]
    #[ORM\JoinColumn(name: 'run_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private SectorIntakeRun $run;

    #[ORM\Column(length: 32)]
    private string $ticker = '';

    #[ORM\Column(length: 64)]
    private string $sectorKey = '';

    #[ORM\Column(length: 128)]
    private string $sectorLabel = '';

    #[ORM\Column]
    private int $sectorRank = 0;

    #[ORM\Column]
    private int $candidateRank = 0;

    #[ORM\Column(length: 32)]
    private string $status = '';

    #[ORM\Column]
    private float $intakeScore = 0.0;

    #[ORM\Column]
    private bool $addedToWatchlist = false;

    #[ORM\Column(length: 32, nullable: true)]
    private ?string $manualAction = null;

    #[ORM\Column(length: 255)]
    private string $reason = '';

    #[ORM\Column(type: Types::JSON)]
    private array $hardChecksJson = [];

    #[ORM\Column(type: Types::JSON)]
    private array $detailJson = [];

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;
}
