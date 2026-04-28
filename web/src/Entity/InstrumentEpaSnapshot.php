<?php

namespace App\Entity;

use App\Repository\InstrumentEpaSnapshotRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: InstrumentEpaSnapshotRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_instrument_epa_as_of', columns: ['instrument_id', 'as_of_date'])]
#[ORM\Index(columns: ['as_of_date'], name: 'idx_instrument_epa_as_of')]
#[ORM\Index(columns: ['action'], name: 'idx_instrument_epa_action')]
class InstrumentEpaSnapshot
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Instrument $instrument;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $asOfDate;

    #[ORM\Column]
    private float $failureScore = 0.0;

    #[ORM\Column]
    private float $trendExitScore = 0.0;

    #[ORM\Column]
    private float $climaxScore = 0.0;

    #[ORM\Column]
    private float $riskScore = 0.0;

    #[ORM\Column]
    private float $totalScore = 0.0;

    #[ORM\Column(length: 24)]
    private string $action = 'HOLD';

    #[ORM\Column(type: Types::JSON)]
    private array $hardTriggersJson = [];

    #[ORM\Column(type: Types::JSON)]
    private array $softWarningsJson = [];

    #[ORM\Column(type: Types::JSON)]
    private array $detailJson = [];

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'source_run_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?PipelineRun $sourceRun = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $availableAt = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $this->asOfDate = new \DateTimeImmutable('today');
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getInstrument(): Instrument { return $this->instrument; }
    public function setInstrument(Instrument $instrument): self { $this->instrument = $instrument; return $this; }
    public function getAsOfDate(): \DateTimeImmutable { return $this->asOfDate; }
    public function setAsOfDate(\DateTimeImmutable $asOfDate): self { $this->asOfDate = $asOfDate; return $this; }
    public function getFailureScore(): float { return $this->failureScore; }
    public function setFailureScore(float $value): self { $this->failureScore = $value; return $this; }
    public function getTrendExitScore(): float { return $this->trendExitScore; }
    public function setTrendExitScore(float $value): self { $this->trendExitScore = $value; return $this; }
    public function getClimaxScore(): float { return $this->climaxScore; }
    public function setClimaxScore(float $value): self { $this->climaxScore = $value; return $this; }
    public function getRiskScore(): float { return $this->riskScore; }
    public function setRiskScore(float $value): self { $this->riskScore = $value; return $this; }
    public function getTotalScore(): float { return $this->totalScore; }
    public function setTotalScore(float $value): self { $this->totalScore = $value; return $this; }
    public function getAction(): string { return $this->action; }
    public function setAction(string $value): self { $this->action = $value; return $this; }
    public function getHardTriggersJson(): array { return $this->hardTriggersJson; }
    public function setHardTriggersJson(array $value): self { $this->hardTriggersJson = $value; return $this; }
    public function getSoftWarningsJson(): array { return $this->softWarningsJson; }
    public function setSoftWarningsJson(array $value): self { $this->softWarningsJson = $value; return $this; }
    public function getDetailJson(): array { return $this->detailJson; }
    public function setDetailJson(array $value): self { $this->detailJson = $value; return $this; }
    public function getSourceRun(): ?PipelineRun { return $this->sourceRun; }
    public function setSourceRun(?PipelineRun $sourceRun): self { $this->sourceRun = $sourceRun; return $this; }
    public function getAvailableAt(): ?\DateTimeImmutable { return $this->availableAt; }
    public function setAvailableAt(?\DateTimeImmutable $availableAt): self { $this->availableAt = $availableAt; return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }
    public function touch(): self { $this->updatedAt = new \DateTimeImmutable(); return $this; }
}
