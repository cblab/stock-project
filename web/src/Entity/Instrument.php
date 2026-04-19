<?php

namespace App\Entity;

use App\Repository\InstrumentRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: InstrumentRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_instrument_input_ticker', columns: ['input_ticker'])]
#[ORM\Index(columns: ['is_portfolio'], name: 'idx_instrument_is_portfolio')]
#[ORM\Index(columns: ['active'], name: 'idx_instrument_active')]
class Instrument
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 32)]
    private string $inputTicker = '';

    #[ORM\Column(length: 64)]
    private string $providerTicker = '';

    #[ORM\Column(length: 32)]
    private string $displayTicker = '';

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $name = null;

    #[ORM\Column(length: 32, nullable: true)]
    private ?string $wkn = null;

    #[ORM\Column(length: 32, nullable: true)]
    private ?string $isin = null;

    #[ORM\Column(length: 32)]
    private string $assetClass = 'Equity';

    #[ORM\Column(length: 32, nullable: true)]
    private ?string $region = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $benchmark = null;

    #[ORM\Column]
    private bool $active = true;

    #[ORM\Column]
    private bool $isPortfolio = true;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $mappingStatus = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $mappingNote = null;

    #[ORM\Column(length: 128, nullable: true)]
    private ?string $contextType = null;

    #[ORM\Column(type: Types::JSON)]
    private array $regionExposure = [];

    #[ORM\Column(type: Types::JSON)]
    private array $sectorProfile = [];

    #[ORM\Column(type: Types::JSON)]
    private array $topHoldingsProfile = [];

    #[ORM\Column(type: Types::JSON)]
    private array $macroProfile = [];

    #[ORM\Column(nullable: true)]
    private ?float $directNewsWeight = null;

    #[ORM\Column(nullable: true)]
    private ?float $contextNewsWeight = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getInputTicker(): string { return $this->inputTicker; }
    public function setInputTicker(string $value): self { $this->inputTicker = $value; return $this; }
    public function getProviderTicker(): string { return $this->providerTicker; }
    public function setProviderTicker(string $value): self { $this->providerTicker = $value; return $this; }
    public function getDisplayTicker(): string { return $this->displayTicker; }
    public function setDisplayTicker(string $value): self { $this->displayTicker = $value; return $this; }
    public function getName(): ?string { return $this->name; }
    public function setName(?string $value): self { $this->name = $value; return $this; }
    public function getWkn(): ?string { return $this->wkn; }
    public function setWkn(?string $value): self { $this->wkn = $value; return $this; }
    public function getIsin(): ?string { return $this->isin; }
    public function setIsin(?string $value): self { $this->isin = $value; return $this; }
    public function getAssetClass(): string { return $this->assetClass; }
    public function setAssetClass(string $value): self { $this->assetClass = $value; return $this; }
    public function getRegion(): ?string { return $this->region; }
    public function setRegion(?string $value): self { $this->region = $value; return $this; }
    public function getBenchmark(): ?string { return $this->benchmark; }
    public function setBenchmark(?string $value): self { $this->benchmark = $value; return $this; }
    public function isActive(): bool { return $this->active; }
    public function setActive(bool $value): self { $this->active = $value; return $this; }
    public function isPortfolio(): bool { return $this->isPortfolio; }
    public function setIsPortfolio(bool $value): self { $this->isPortfolio = $value; return $this; }
    public function getMappingStatus(): ?string { return $this->mappingStatus; }
    public function setMappingStatus(?string $value): self { $this->mappingStatus = $value; return $this; }
    public function getMappingNote(): ?string { return $this->mappingNote; }
    public function setMappingNote(?string $value): self { $this->mappingNote = $value; return $this; }
    public function getContextType(): ?string { return $this->contextType; }
    public function setContextType(?string $value): self { $this->contextType = $value; return $this; }
    public function getRegionExposure(): array { return $this->regionExposure; }
    public function setRegionExposure(array $value): self { $this->regionExposure = array_values($value); return $this; }
    public function getSectorProfile(): array { return $this->sectorProfile; }
    public function setSectorProfile(array $value): self { $this->sectorProfile = array_values($value); return $this; }
    public function getTopHoldingsProfile(): array { return $this->topHoldingsProfile; }
    public function setTopHoldingsProfile(array $value): self { $this->topHoldingsProfile = array_values($value); return $this; }
    public function getMacroProfile(): array { return $this->macroProfile; }
    public function setMacroProfile(array $value): self { $this->macroProfile = array_values($value); return $this; }
    public function getDirectNewsWeight(): ?float { return $this->directNewsWeight; }
    public function setDirectNewsWeight(?float $value): self { $this->directNewsWeight = $value; return $this; }
    public function getContextNewsWeight(): ?float { return $this->contextNewsWeight; }
    public function setContextNewsWeight(?float $value): self { $this->contextNewsWeight = $value; return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }
    public function touch(): self { $this->updatedAt = new \DateTimeImmutable(); return $this; }
}
