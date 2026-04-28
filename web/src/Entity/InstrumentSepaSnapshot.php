<?php

namespace App\Entity;

use App\Repository\InstrumentSepaSnapshotRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: InstrumentSepaSnapshotRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_instrument_sepa_as_of', columns: ['instrument_id', 'as_of_date'])]
#[ORM\Index(columns: ['as_of_date'], name: 'idx_instrument_sepa_as_of')]
#[ORM\Index(columns: ['traffic_light'], name: 'idx_instrument_sepa_traffic_light')]
class InstrumentSepaSnapshot
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
    private float $marketScore = 0.0;

    #[ORM\Column]
    private float $stageScore = 0.0;

    #[ORM\Column]
    private float $relativeStrengthScore = 0.0;

    #[ORM\Column]
    private float $baseQualityScore = 0.0;

    #[ORM\Column]
    private float $volumeScore = 0.0;

    #[ORM\Column]
    private float $momentumScore = 0.0;

    #[ORM\Column]
    private float $riskScore = 0.0;

    #[ORM\Column]
    private float $superperformanceScore = 0.0;

    #[ORM\Column]
    private float $vcpScore = 0.0;

    #[ORM\Column]
    private float $microstructureScore = 0.0;

    #[ORM\Column]
    private float $breakoutReadinessScore = 0.0;

    #[ORM\Column]
    private float $structureScore = 0.0;

    #[ORM\Column]
    private float $executionScore = 0.0;

    #[ORM\Column]
    private float $totalScore = 0.0;

    #[ORM\Column(length: 16)]
    private string $trafficLight = 'Rot';

    #[ORM\Column(type: Types::JSON)]
    private array $killTriggersJson = [];

    #[ORM\Column(type: Types::JSON)]
    private array $detailJson = [];

    #[ORM\Column(type: Types::FLOAT, nullable: true)]
    private ?float $forwardReturn5d = null;

    #[ORM\Column(type: Types::FLOAT, nullable: true)]
    private ?float $forwardReturn20d = null;

    #[ORM\Column(type: Types::FLOAT, nullable: true)]
    private ?float $forwardReturn60d = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
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
    public function getMarketScore(): float { return $this->marketScore; }
    public function setMarketScore(float $value): self { $this->marketScore = $value; return $this; }
    public function getStageScore(): float { return $this->stageScore; }
    public function setStageScore(float $value): self { $this->stageScore = $value; return $this; }
    public function getRelativeStrengthScore(): float { return $this->relativeStrengthScore; }
    public function setRelativeStrengthScore(float $value): self { $this->relativeStrengthScore = $value; return $this; }
    public function getBaseQualityScore(): float { return $this->baseQualityScore; }
    public function setBaseQualityScore(float $value): self { $this->baseQualityScore = $value; return $this; }
    public function getVolumeScore(): float { return $this->volumeScore; }
    public function setVolumeScore(float $value): self { $this->volumeScore = $value; return $this; }
    public function getMomentumScore(): float { return $this->momentumScore; }
    public function setMomentumScore(float $value): self { $this->momentumScore = $value; return $this; }
    public function getRiskScore(): float { return $this->riskScore; }
    public function setRiskScore(float $value): self { $this->riskScore = $value; return $this; }
    public function getSuperperformanceScore(): float { return $this->superperformanceScore; }
    public function setSuperperformanceScore(float $value): self { $this->superperformanceScore = $value; return $this; }
    public function getVcpScore(): float { return $this->vcpScore; }
    public function setVcpScore(float $value): self { $this->vcpScore = $value; return $this; }
    public function getMicrostructureScore(): float { return $this->microstructureScore; }
    public function setMicrostructureScore(float $value): self { $this->microstructureScore = $value; return $this; }
    public function getBreakoutReadinessScore(): float { return $this->breakoutReadinessScore; }
    public function setBreakoutReadinessScore(float $value): self { $this->breakoutReadinessScore = $value; return $this; }
    public function getStructureScore(): float { return $this->structureScore; }
    public function setStructureScore(float $value): self { $this->structureScore = $value; return $this; }
    public function getExecutionScore(): float { return $this->executionScore; }
    public function setExecutionScore(float $value): self { $this->executionScore = $value; return $this; }
    public function getTotalScore(): float { return $this->totalScore; }
    public function setTotalScore(float $value): self { $this->totalScore = $value; return $this; }
    public function getTrafficLight(): string { return $this->trafficLight; }
    public function setTrafficLight(string $value): self { $this->trafficLight = $value; return $this; }
    public function getKillTriggersJson(): array { return $this->killTriggersJson; }
    public function setKillTriggersJson(array $value): self { $this->killTriggersJson = $value; return $this; }
    public function getDetailJson(): array { return $this->detailJson; }
    public function setDetailJson(array $value): self { $this->detailJson = $value; return $this; }
    public function getForwardReturn5d(): ?float { return $this->forwardReturn5d; }
    public function setForwardReturn5d(?float $value): self { $this->forwardReturn5d = $value; return $this; }
    public function getForwardReturn20d(): ?float { return $this->forwardReturn20d; }
    public function setForwardReturn20d(?float $value): self { $this->forwardReturn20d = $value; return $this; }
    public function getForwardReturn60d(): ?float { return $this->forwardReturn60d; }
    public function setForwardReturn60d(?float $value): self { $this->forwardReturn60d = $value; return $this; }
    public function getSourceRun(): ?PipelineRun { return $this->sourceRun; }
    public function setSourceRun(?PipelineRun $sourceRun): self { $this->sourceRun = $sourceRun; return $this; }
    public function getAvailableAt(): ?\DateTimeImmutable { return $this->availableAt; }
    public function setAvailableAt(?\DateTimeImmutable $availableAt): self { $this->availableAt = $availableAt; return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }
    public function touch(): self { $this->updatedAt = new \DateTimeImmutable(); return $this; }
}
