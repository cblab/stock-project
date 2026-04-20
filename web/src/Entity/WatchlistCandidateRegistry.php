<?php

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'watchlist_candidate_registry')]
#[ORM\UniqueConstraint(name: 'uniq_watchlist_candidate_registry_ticker', columns: ['ticker'])]
#[ORM\Index(columns: ['latest_status'], name: 'idx_watchlist_candidate_registry_status')]
#[ORM\Index(columns: ['manual_state'], name: 'idx_watchlist_candidate_registry_manual_state')]
#[ORM\Index(columns: ['active_candidate'], name: 'idx_watchlist_candidate_registry_active')]
#[ORM\Index(columns: ['latest_intake_score'], name: 'idx_watchlist_candidate_registry_score')]
#[ORM\Index(columns: ['last_seen_at'], name: 'idx_watchlist_candidate_registry_last_seen')]
#[ORM\Index(columns: ['active_candidate', 'last_seen_at', 'id'], name: 'idx_watchlist_candidate_registry_active_seen')]
#[ORM\Index(columns: ['latest_run_id'], name: 'IDX_WATCHLIST_CANDIDATE_REGISTRY_RUN')]
#[ORM\Index(columns: ['latest_candidate_id'], name: 'IDX_WATCHLIST_CANDIDATE_REGISTRY_CANDIDATE')]
class WatchlistCandidateRegistry
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 32)]
    private string $ticker = '';

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $name = null;

    #[ORM\Column(length: 64)]
    private string $sectorKey = '';

    #[ORM\Column(length: 128)]
    private string $sectorLabel = '';

    #[ORM\Column]
    private \DateTimeImmutable $firstSeenAt;

    #[ORM\Column]
    private \DateTimeImmutable $lastSeenAt;

    #[ORM\Column]
    private int $seenCount = 0;

    #[ORM\Column]
    private float $latestIntakeScore = 0.0;

    #[ORM\Column]
    private float $bestIntakeScore = 0.0;

    #[ORM\Column(length: 32)]
    private string $latestStatus = '';

    #[ORM\Column(length: 32, nullable: true)]
    private ?string $manualState = null;

    #[ORM\Column]
    private bool $activeCandidate = true;

    #[ORM\Column(length: 255)]
    private string $latestReason = '';

    #[ORM\Column(type: Types::JSON)]
    private array $latestBuySignalsJson = [];

    #[ORM\Column(type: Types::JSON)]
    private array $latestSepaJson = [];

    #[ORM\Column(type: Types::JSON)]
    private array $latestEpaJson = [];

    #[ORM\Column(type: Types::JSON)]
    private array $latestDetailJson = [];

    #[ORM\ManyToOne(targetEntity: SectorIntakeRun::class)]
    #[ORM\JoinColumn(name: 'latest_run_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?SectorIntakeRun $latestRun = null;

    #[ORM\ManyToOne(targetEntity: SectorIntakeCandidate::class)]
    #[ORM\JoinColumn(name: 'latest_candidate_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?SectorIntakeCandidate $latestCandidate = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;
}
