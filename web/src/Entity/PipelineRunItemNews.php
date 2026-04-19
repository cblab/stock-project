<?php

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
class PipelineRunItemNews
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private PipelineRunItem $pipelineRunItem;

    #[ORM\Column(length: 128, nullable: true)]
    private ?string $source = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $publishedAt = null;

    #[ORM\Column(type: Types::TEXT)]
    private string $headline = '';

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $snippet = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $articleSentimentLabel = null;

    #[ORM\Column(nullable: true)]
    private ?float $articleSentimentConfidence = null;

    #[ORM\Column(length: 32, nullable: true)]
    private ?string $relevance = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $contextKind = null;

    #[ORM\Column(type: Types::JSON)]
    private array $rawPayload = [];

    public function getId(): ?int { return $this->id; }
    public function getPipelineRunItem(): PipelineRunItem { return $this->pipelineRunItem; }
    public function setPipelineRunItem(PipelineRunItem $value): self { $this->pipelineRunItem = $value; return $this; }
    public function setSource(?string $value): self { $this->source = $value; return $this; }
    public function setPublishedAt(?\DateTimeImmutable $value): self { $this->publishedAt = $value; return $this; }
    public function setHeadline(string $value): self { $this->headline = $value; return $this; }
    public function setSnippet(?string $value): self { $this->snippet = $value; return $this; }
    public function setArticleSentimentLabel(?string $value): self { $this->articleSentimentLabel = $value; return $this; }
    public function setArticleSentimentConfidence(?float $value): self { $this->articleSentimentConfidence = $value; return $this; }
    public function setRelevance(?string $value): self { $this->relevance = $value; return $this; }
    public function setContextKind(?string $value): self { $this->contextKind = $value; return $this; }
    public function setRawPayload(array $value): self { $this->rawPayload = $value; return $this; }
}
