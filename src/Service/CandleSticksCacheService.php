<?php

namespace App\Service;

use App\Entity\CandleStick;
use Symfony\Contracts\Cache\CacheInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\Cache\ItemInterface;

class CandleSticksCacheService
{
    private const EXPIRATION_TIME = 3600;

    private $entityManager;
    private $cache;
    

    public function __construct(EntityManagerInterface $entityManager, CacheInterface $cache)
    {
        $this->entityManager = $entityManager;
        $this->cache = $cache;
    }

    public function getCandlesticks(): array
    {
        return $this->cache->get('candlesticks_data', function (ItemInterface $item) {
            // Set cache expiration to 1 hour (or adjust as needed)
            $item->expiresAfter(self::EXPIRATION_TIME);

            // Fetch data from the database
            return $this->entityManager->getRepository(CandleStick::class)->findAll();
        });
    }

    public function getCandlesticksBySecurityId(int $securityId): array
    {
        // Check if the filtered data is already in cache
        return $this->cache->get('candlesticks_data_security' . $securityId, function (ItemInterface $item) use ($securityId) {
            $item->expiresAfter(self::EXPIRATION_TIME); // Set cache expiration for filtered data

            // Get the full cached candlesticks array
            $allCandlesticks = $this->getCandlesticks();

            // Extract and filter candlesticks with the specified security_id
            $filteredCandlesticks = array_filter($allCandlesticks, function ($candlestick) use ($securityId) {
                return $candlestick->getSecurity()->getId() === $securityId;
            });

            return $filteredCandlesticks;
        });
    }
}