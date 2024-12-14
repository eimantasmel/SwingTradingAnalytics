<?php

namespace App\Entity;

use App\Repository\SecurityRepository;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\Common\Collections\Collection;
use Doctrine\Common\Collections\ArrayCollection;
use DateTime;

#[ORM\Entity(repositoryClass: SecurityRepository::class)]
#[ORM\Table(name: 'Securities')]
class Security
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: 'string', nullable: false, length: 20)]
    private string $ticker;

    #[ORM\Column(name: 'is_crypto', type: 'boolean', nullable: true)]
    private ?string $isCrypto = null;

    #[ORM\Column(name: 'is_forex', type: 'boolean', nullable: true)]
    private ?string $isForex = null;

    #[ORM\Column(type: 'string', nullable: true)]
    private ?string $description = null;

    #[ORM\OneToMany(mappedBy: 'security', targetEntity: CandleStick::class, cascade: ['remove'], orphanRemoval: true)]
    private Collection $candleSticks;

    #[ORM\Column(type: 'string', nullable: true)]
    private ?string $sector = null;

    #[ORM\Column(type: 'string', nullable: true)]
    private ?string $industry = null;
    
    public function __construct()
    {
        $this->candleSticks = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    // Getter and Setter for ticker
    public function getTicker(): string
    {
        return $this->ticker;
    }

    public function setTicker(string $ticker): self
    {
        $this->ticker = $ticker;
        return $this;
    }

    // Getter and Setter for isCrypto
    public function getIsCrypto(): ?bool
    {
        return $this->isCrypto;
    }

    public function setIsCrypto(?bool $isCrypto): self
    {
        $this->isCrypto = $isCrypto;
        return $this;
    }

    // Getter and Setter for isCrypto
    public function getIsForex(): ?bool
    {
        return $this->isForex;
    }

    public function setIsForex(?bool $isForex): self
    {
        $this->isForex = $isForex;
        return $this;
    }

    // Getter and Setter for description
    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): self
    {
        $this->description = $description;
        return $this;
    }

    public function getSector(): ?string
    {
        return $this->sector;
    }

    public function setSector(?string $sector): self
    {
        $this->sector = $sector;
        return $this;
    }

    public function getIndustry(): ?string
    {
        return $this->industry;
    }

    public function setIndustry(?string $industry): self
    {
        $this->industry = $industry;
        return $this;
    }

    // Getter and Setter for candleSticks
    /**
     */
    public function getCandleSticks(): ArrayCollection
    {
        $sortedCandleSticks = $this->sortCandleSticksByDate($this->candleSticks);
        return $sortedCandleSticks;
    }

    public function addCandleStick(CandleStick $candleStick): self
    {
        if (!$this->candleSticks->contains($candleStick)) {
            $this->candleSticks->add($candleStick);
            $candleStick->setSecurity($this);
        }
        return $this;
    }

    public function removeCandleStick(CandleStick $candleStick): self
    {
        if ($this->candleSticks->removeElement($candleStick) && $candleStick->getSecurity() === $this) {
            $candleStick->setSecurity(null);
        }
        return $this;
    }

    /**
     * This method return candlestick by date or the next following candlestick if specific date is not found
     */
    public function getCandleStickByDate(DateTime $searchDate) : ?CandleStick
    {
        $candleSticks = $this->getCandleSticks();
        foreach ($candleSticks as $candleStick) {
            $date = $candleStick->getDate();
            if($searchDate->diff($date)->days == 0)
            {
                return $candleStick;
            }

            if($date > $searchDate)
                return $candleStick;

        }

        return null;
    }

    public function getLastNCandleSticks(DateTime $tradingDate, $amountOfCandleSticks) : array
    {
        $lastCandleSticks = [];
        $candleSticks = $this->getCandleSticks();
        foreach ($candleSticks as $candleStick) {
            $date = $candleStick->getDate();
            if($tradingDate->diff($date)->days < $amountOfCandleSticks && $tradingDate >= $date)
            {
                $lastCandleSticks[] = $candleStick;
            }
        }

        return $lastCandleSticks;
    }

    public function getNextNCandleSticks(DateTime $tradingDate, $amountOfCandleSticks) : array
    {
        $nextCandleSticks = [];
        $candleSticks = $this->getCandleSticks();
        foreach ($candleSticks as $candleStick) {
            $date = $candleStick->getDate();
            if($date->diff($tradingDate)->days < $amountOfCandleSticks && $date >= $tradingDate)
            {
                $nextCandleSticks[] = $candleStick;
            }
        }

        return $nextCandleSticks;
    }

    public function getFirstCandleStick()
    {
        $candleStick = $this->getCandleSticks()->first();
        return $candleStick;
    }

    public function getLastCandleStick()
    {
        $candleStick = $this->getCandleSticks()->last();
        return $candleStick;
    }

    public function isDateExist($date) : bool
    {
        $candleSticks = $this->getCandleSticks();
        foreach ($candleSticks as $candleStick) {
            $candleStickDate = $candleStick->getDate();
            if($candleStickDate->diff($date)->days == 0)
                return true;
        }
        
        return false;
    }

    private function sortCandleSticksByDate(Collection $candlesticks): Collection
    {
        $candleSticksArray = $candlesticks->toArray();
    
        usort($candleSticksArray, function (CandleStick $a, CandleStick $b) {
            return $a->getDate() <=> $b->getDate();
        });
    
        return new ArrayCollection($candleSticksArray); 

    }
}
