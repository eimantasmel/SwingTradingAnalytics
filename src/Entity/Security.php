<?php

namespace App\Entity;

use App\Repository\SecurityRepository;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\Common\Collections\Collection;
use Doctrine\Common\Collections\ArrayCollection;

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

    #[ORM\Column(type: 'string', nullable: true)]
    private ?string $description = null;

    #[ORM\OneToMany(mappedBy: 'stock', targetEntity: CandleStick::class, cascade: ['remove'], orphanRemoval: true)]
    private Collection $candleSticks;

    
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

    // Getter and Setter for candleSticks
    /**
     * @return Collection<int, CandleStick>
     */
    public function getCandleSticks(): Collection
    {
        return $this->candleSticks;
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
}
