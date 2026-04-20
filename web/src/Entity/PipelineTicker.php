<?php

namespace App\Entity;

use App\Repository\PipelineTickerRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Legacy pre-instrument run item table.
 *
 * New DB-first pipeline paths write PipelineRunItem. This entity is retained so
 * older imported runs remain readable and rollback migrations stay valid.
 */
#[ORM\Entity(repositoryClass: PipelineTickerRepository::class)]
#[ORM\Index(columns: ['decision'], name: 'idx_pipeline_ticker_decision')]
#[ORM\Index(columns: ['asset_class'], name: 'idx_pipeline_ticker_asset_class')]
#[ORM\Index(columns: ['sentiment_mode'], name: 'idx_pipeline_ticker_sentiment_mode')]
class PipelineTicker
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'tickers')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private PipelineRun $pipelineRun;

    #[ORM\Column(length: 32)]
    private string $inputTicker = '';

    #[ORM\Column(length: 64)]
    private string $providerTicker = '';

    #[ORM\Column(length: 32)]
    private string $displayTicker = '';

    #[ORM\Column(length: 32)]
    private string $assetClass = 'Equity';

    #[ORM\Column(length: 32, nullable: true)]
    private ?string $region = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $sentimentMode = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $mappingStatus = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $mappingNote = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $marketDataStatus = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $newsStatus = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $kronosStatus = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $sentimentStatus = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $kronosDirection = null;

    #[ORM\Column(nullable: true)]
    private ?float $kronosRawScore = null;

    #[ORM\Column(nullable: true)]
    private ?float $kronosNormalizedScore = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $sentimentLabel = null;

    #[ORM\Column(nullable: true)]
    private ?float $sentimentRawScore = null;

    #[ORM\Column(nullable: true)]
    private ?float $sentimentNormalizedScore = null;

    #[ORM\Column(nullable: true)]
    private ?float $sentimentConfidence = null;

    #[ORM\Column(length: 128, nullable: true)]
    private ?string $sentimentBackend = null;

    #[ORM\Column(nullable: true)]
    private ?float $mergedScore = null;

    #[ORM\Column(length: 64)]
    private string $decision = 'DATA ERROR';

    #[ORM\Column(type: Types::JSON)]
    private array $explainJson = [];

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getPipelineRun(): PipelineRun { return $this->pipelineRun; }
    public function setPipelineRun(PipelineRun $pipelineRun): self { $this->pipelineRun = $pipelineRun; return $this; }
    public function getInputTicker(): string { return $this->inputTicker; }
    public function setInputTicker(string $value): self { $this->inputTicker = $value; return $this; }
    public function getProviderTicker(): string { return $this->providerTicker; }
    public function setProviderTicker(string $value): self { $this->providerTicker = $value; return $this; }
    public function getDisplayTicker(): string { return $this->displayTicker; }
    public function setDisplayTicker(string $value): self { $this->displayTicker = $value; return $this; }
    public function getAssetClass(): string { return $this->assetClass; }
    public function setAssetClass(string $value): self { $this->assetClass = $value; return $this; }
    public function getRegion(): ?string { return $this->region; }
    public function setRegion(?string $value): self { $this->region = $value; return $this; }
    public function getSentimentMode(): ?string { return $this->sentimentMode; }
    public function setSentimentMode(?string $value): self { $this->sentimentMode = $value; return $this; }
    public function getMappingStatus(): ?string { return $this->mappingStatus; }
    public function setMappingStatus(?string $value): self { $this->mappingStatus = $value; return $this; }
    public function getMappingNote(): ?string { return $this->mappingNote; }
    public function setMappingNote(?string $value): self { $this->mappingNote = $value; return $this; }
    public function getMarketDataStatus(): ?string { return $this->marketDataStatus; }
    public function setMarketDataStatus(?string $value): self { $this->marketDataStatus = $value; return $this; }
    public function getNewsStatus(): ?string { return $this->newsStatus; }
    public function setNewsStatus(?string $value): self { $this->newsStatus = $value; return $this; }
    public function getKronosStatus(): ?string { return $this->kronosStatus; }
    public function setKronosStatus(?string $value): self { $this->kronosStatus = $value; return $this; }
    public function getSentimentStatus(): ?string { return $this->sentimentStatus; }
    public function setSentimentStatus(?string $value): self { $this->sentimentStatus = $value; return $this; }
    public function getKronosDirection(): ?string { return $this->kronosDirection; }
    public function setKronosDirection(?string $value): self { $this->kronosDirection = $value; return $this; }
    public function getKronosRawScore(): ?float { return $this->kronosRawScore; }
    public function setKronosRawScore(?float $value): self { $this->kronosRawScore = $value; return $this; }
    public function getKronosNormalizedScore(): ?float { return $this->kronosNormalizedScore; }
    public function setKronosNormalizedScore(?float $value): self { $this->kronosNormalizedScore = $value; return $this; }
    public function getSentimentLabel(): ?string { return $this->sentimentLabel; }
    public function setSentimentLabel(?string $value): self { $this->sentimentLabel = $value; return $this; }
    public function getSentimentRawScore(): ?float { return $this->sentimentRawScore; }
    public function setSentimentRawScore(?float $value): self { $this->sentimentRawScore = $value; return $this; }
    public function getSentimentNormalizedScore(): ?float { return $this->sentimentNormalizedScore; }
    public function setSentimentNormalizedScore(?float $value): self { $this->sentimentNormalizedScore = $value; return $this; }
    public function getSentimentConfidence(): ?float { return $this->sentimentConfidence; }
    public function setSentimentConfidence(?float $value): self { $this->sentimentConfidence = $value; return $this; }
    public function getSentimentBackend(): ?string { return $this->sentimentBackend; }
    public function setSentimentBackend(?string $value): self { $this->sentimentBackend = $value; return $this; }
    public function getMergedScore(): ?float { return $this->mergedScore; }
    public function setMergedScore(?float $value): self { $this->mergedScore = $value; return $this; }
    public function getDecision(): string { return $this->decision; }
    public function setDecision(string $value): self { $this->decision = $value; return $this; }
    public function getExplainJson(): array { return $this->explainJson; }
    public function setExplainJson(array $value): self { $this->explainJson = $value; return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function setCreatedAt(\DateTimeImmutable $value): self { $this->createdAt = $value; return $this; }
}
