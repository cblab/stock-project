<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\InstrumentPriceHistoryRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: InstrumentPriceHistoryRepository::class)]
#[ORM\Table(name: 'instrument_price_history')]
#[ORM\UniqueConstraint(name: 'uniq_instrument_price_date', columns: ['instrument_id', 'price_date'])]
#[ORM\Index(columns: ['price_date'], name: 'idx_price_date')]
class InstrumentPriceHistory
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Instrument::class)]
    #[ORM\JoinColumn(name: 'instrument_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?Instrument $instrument = null;

    #[ORM\Column(name: 'price_date', type: Types::DATE_IMMUTABLE)]
    private ?\DateTimeImmutable $priceDate = null;

    #[ORM\Column(name: 'open_price', type: Types::DECIMAL, precision: 15, scale: 4, nullable: true)]
    private ?string $open = null;

    #[ORM\Column(name: 'high_price', type: Types::DECIMAL, precision: 15, scale: 4, nullable: true)]
    private ?string $high = null;

    #[ORM\Column(name: 'low_price', type: Types::DECIMAL, precision: 15, scale: 4, nullable: true)]
    private ?string $low = null;

    #[ORM\Column(name: 'close_price', type: Types::DECIMAL, precision: 15, scale: 4, nullable: true)]
    private ?string $close = null;

    #[ORM\Column(name: 'adj_close', type: Types::DECIMAL, precision: 15, scale: 4, nullable: true)]
    private ?string $adjClose = null;

    #[ORM\Column(name: 'volume', type: Types::BIGINT, nullable: true)]
    private ?int $volume = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getInstrument(): ?Instrument
    {
        return $this->instrument;
    }

    public function setInstrument(?Instrument $instrument): self
    {
        $this->instrument = $instrument;
        return $this;
    }

    public function getPriceDate(): ?\DateTimeImmutable
    {
        return $this->priceDate;
    }

    public function setPriceDate(\DateTimeImmutable $priceDate): self
    {
        $this->priceDate = $priceDate;
        return $this;
    }

    public function getOpen(): ?string
    {
        return $this->open;
    }

    public function setOpen(?string $open): self
    {
        $this->open = $open;
        return $this;
    }

    public function getHigh(): ?string
    {
        return $this->high;
    }

    public function setHigh(?string $high): self
    {
        $this->high = $high;
        return $this;
    }

    public function getLow(): ?string
    {
        return $this->low;
    }

    public function setLow(?string $low): self
    {
        $this->low = $low;
        return $this;
    }

    public function getClose(): ?string
    {
        return $this->close;
    }

    public function setClose(?string $close): self
    {
        $this->close = $close;
        return $this;
    }

    public function getAdjClose(): ?string
    {
        return $this->adjClose;
    }

    public function setAdjClose(?string $adjClose): self
    {
        $this->adjClose = $adjClose;
        return $this;
    }

    public function getVolume(): ?int
    {
        return $this->volume;
    }

    public function setVolume(?int $volume): self
    {
        $this->volume = $volume;
        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function touch(): self
    {
        $this->updatedAt = new \DateTimeImmutable();
        return $this;
    }
}
