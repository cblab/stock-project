<?php

namespace App\Entity;

use App\Repository\PipelineRunRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: PipelineRunRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_pipeline_run_run_id', columns: ['run_id'])]
class PipelineRun
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 64)]
    private string $runId = '';

    #[ORM\Column(length: 1024)]
    private string $runPath = '';

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $startedAt = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(length: 32, nullable: true)]
    private ?string $dataFrequency = null;

    #[ORM\Column(nullable: true)]
    private ?int $horizonSteps = null;

    #[ORM\Column(length: 128, nullable: true)]
    private ?string $horizonLabel = null;

    #[ORM\Column(nullable: true)]
    private ?int $scoreValidityHours = null;

    #[ORM\Column]
    private bool $summaryGenerated = false;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $notes = null;

    #[ORM\Column]
    private int $decisionEntryCount = 0;

    #[ORM\Column]
    private int $decisionWatchCount = 0;

    #[ORM\Column]
    private int $decisionHoldCount = 0;

    #[ORM\Column]
    private int $decisionNoTradeCount = 0;

    #[ORM\Column(nullable: true)]
    private ?float $scoreMin = null;

    #[ORM\Column(nullable: true)]
    private ?float $scoreMax = null;

    #[ORM\Column(nullable: true)]
    private ?float $scoreMean = null;

    #[ORM\Column(nullable: true)]
    private ?float $scoreMedian = null;

    /** @var Collection<int, PipelineTicker> */
    #[ORM\OneToMany(mappedBy: 'pipelineRun', targetEntity: PipelineTicker::class, cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['mergedScore' => 'DESC'])]
    private Collection $tickers;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->tickers = new ArrayCollection();
    }

    public function getId(): ?int { return $this->id; }
    public function getRunId(): string { return $this->runId; }
    public function setRunId(string $runId): self { $this->runId = $runId; return $this; }
    public function getRunPath(): string { return $this->runPath; }
    public function setRunPath(string $runPath): self { $this->runPath = $runPath; return $this; }
    public function getStartedAt(): ?\DateTimeImmutable { return $this->startedAt; }
    public function setStartedAt(?\DateTimeImmutable $startedAt): self { $this->startedAt = $startedAt; return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function setCreatedAt(\DateTimeImmutable $createdAt): self { $this->createdAt = $createdAt; return $this; }
    public function getDataFrequency(): ?string { return $this->dataFrequency; }
    public function setDataFrequency(?string $dataFrequency): self { $this->dataFrequency = $dataFrequency; return $this; }
    public function getHorizonSteps(): ?int { return $this->horizonSteps; }
    public function setHorizonSteps(?int $horizonSteps): self { $this->horizonSteps = $horizonSteps; return $this; }
    public function getHorizonLabel(): ?string { return $this->horizonLabel; }
    public function setHorizonLabel(?string $horizonLabel): self { $this->horizonLabel = $horizonLabel; return $this; }
    public function getScoreValidityHours(): ?int { return $this->scoreValidityHours; }
    public function setScoreValidityHours(?int $scoreValidityHours): self { $this->scoreValidityHours = $scoreValidityHours; return $this; }
    public function isSummaryGenerated(): bool { return $this->summaryGenerated; }
    public function setSummaryGenerated(bool $summaryGenerated): self { $this->summaryGenerated = $summaryGenerated; return $this; }
    public function getNotes(): ?string { return $this->notes; }
    public function setNotes(?string $notes): self { $this->notes = $notes; return $this; }
    public function getDecisionEntryCount(): int { return $this->decisionEntryCount; }
    public function setDecisionEntryCount(int $count): self { $this->decisionEntryCount = $count; return $this; }
    public function getDecisionWatchCount(): int { return $this->decisionWatchCount; }
    public function setDecisionWatchCount(int $count): self { $this->decisionWatchCount = $count; return $this; }
    public function getDecisionHoldCount(): int { return $this->decisionHoldCount; }
    public function setDecisionHoldCount(int $count): self { $this->decisionHoldCount = $count; return $this; }
    public function getDecisionNoTradeCount(): int { return $this->decisionNoTradeCount; }
    public function setDecisionNoTradeCount(int $count): self { $this->decisionNoTradeCount = $count; return $this; }
    public function getScoreMin(): ?float { return $this->scoreMin; }
    public function setScoreMin(?float $scoreMin): self { $this->scoreMin = $scoreMin; return $this; }
    public function getScoreMax(): ?float { return $this->scoreMax; }
    public function setScoreMax(?float $scoreMax): self { $this->scoreMax = $scoreMax; return $this; }
    public function getScoreMean(): ?float { return $this->scoreMean; }
    public function setScoreMean(?float $scoreMean): self { $this->scoreMean = $scoreMean; return $this; }
    public function getScoreMedian(): ?float { return $this->scoreMedian; }
    public function setScoreMedian(?float $scoreMedian): self { $this->scoreMedian = $scoreMedian; return $this; }

    /** @return Collection<int, PipelineTicker> */
    public function getTickers(): Collection { return $this->tickers; }

    public function addTicker(PipelineTicker $ticker): self
    {
        if (!$this->tickers->contains($ticker)) {
            $this->tickers->add($ticker);
            $ticker->setPipelineRun($this);
        }

        return $this;
    }

    public function removeTicker(PipelineTicker $ticker): self
    {
        $this->tickers->removeElement($ticker);
        return $this;
    }
}
