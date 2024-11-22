<?php

namespace App\Entity;

use App\Repository\CandleStickRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: CandleStickRepository::class)]
#[ORM\Table(name: 'CandleSticks')]
class CandleStick
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: 'date', nullable: false)]
    private ?\DateTimeInterface $date = null;

    #[ORM\Column(name: 'open_price', type: 'decimal', precision: 14, scale: 6, nullable: true)]
    private ?string $openPrice = null;

    #[ORM\Column(name: 'highest_price', type: 'decimal', precision: 14, scale: 6, nullable: true)]
    private ?string $highestPrice = null;

    #[ORM\Column(name: 'lowest_price', type: 'decimal', precision: 14, scale: 6, nullable: true)]
    private ?string $lowestPrice = null;

    #[ORM\Column(name: 'close_price', type: 'decimal', precision: 14, scale: 6, nullable: true)]
    private ?string $closePrice = null;

    #[ORM\Column(name: 'volume', type: 'decimal', precision: 14, scale: 2, nullable: true)]
    private ?string $volume = null;

    #[ORM\ManyToOne(inversedBy: 'candlesticks')]
    #[ORM\JoinColumn(nullable: false,  onDelete: 'CASCADE')]
    private ?Security $security = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    // Getter and setter for $date
    public function getDate(): ?\DateTimeInterface
    {
        return $this->date;
    }

    public function setDate(\DateTimeInterface $date): self
    {
        $this->date = $date;
        return $this;
    }

    // Getter and setter for $cryptoPrice
    public function getOpenPrice(): ?string
    {
        return $this->openPrice;
    }

    public function setOpenPrice(?string $openPrice): self
    {
        $this->openPrice = $openPrice;
        return $this;
    }

    // Getter and Setter for highestPrice
    public function getHighestPrice(): ?string
    {
        return $this->highestPrice;
    }

    public function setHighestPrice(?string $highestPrice): self
    {
        $this->highestPrice = $highestPrice;
        return $this;
    }

    // Getter and Setter for lowestPrice
    public function getLowestPrice(): ?string
    {
        return $this->lowestPrice;
    }

    public function setLowestPrice(?string $lowestPrice): self
    {
        $this->lowestPrice = $lowestPrice;
        return $this;
    }

    // Getter and Setter for closePrice
    public function getClosePrice(): ?string
    {
        return $this->closePrice;
    }

    public function setClosePrice(?string $closePrice): self
    {
        $this->closePrice = $closePrice;
        return $this;
    }

    // Getter and setter for $volume
    public function getVolume(): ?string
    {
        return $this->volume;
    }

    public function setVolume(?string $volume): self
    {
        $this->volume = $volume;
        return $this;
    }

    // Getter and setter for $crypto
    public function getSecurity(): ?Security
    {
        return $this->security;
    }

    public function setSecurity(?Security $crypto): self
    {
        $this->security = $crypto;
        return $this;
    }
}
