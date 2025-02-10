<?php

namespace App\Service;

use DateTime;
use App\Constants\BaseConstants;
use Doctrine\ORM\EntityManagerInterface;
use App\Entity\Security;
use App\Entity\CandleStick;
use App\Service\YahooWebScrapService;
use App\Interface\MarketIndexInterface;

class CryptoMarketIndexService implements MarketIndexInterface
{
    private const MAIN_CRYPTO = 'BTC';

    private EntityManagerInterface $entityManager;
    private Security $nasdaq2000Data;
    private YahooWebScrapService $yahooWebScrapService;

    public function __construct(EntityManagerInterface $entityManager,
                                YahooWebScrapService $yahooWebScrapService) {
        $this->entityManager = $entityManager;
        $this->yahooWebScrapService = $yahooWebScrapService;
        $this->nasdaq2000Data = $this->entityManager
        ->getRepository(Security::class)
        ->findOneBy(['ticker' => BaseConstants::CRYPTO_MARKET_TICKER]);
    }

    public function getCagrOfDates(DateTime $startDate, DateTime $endDate, bool $isRecursion = false) : ?float
    {
        $startCandleStick = $this->nasdaq2000Data->getCandleStickByDate($startDate);
        $endCandleStick = $this->nasdaq2000Data->getCandleStickByDate($endDate);

        if(!$startCandleStick || !$endCandleStick)
        {
            if($isRecursion)
                return null;
            
            $this->updateMarketData();
            return $this->getCagrOfDates($startDate, $endDate, true);
        }



        $startPrice = (float)$startCandleStick->getClosePrice();
        $endPrice = (float)$endCandleStick->getClosePrice();
        
        if(!$startPrice)
            return null;

        $cagr = ($endPrice / $startPrice) - 1;

        return $cagr;
    }

    public function updateMarketData()
    {
        echo "Updating crypto market index" . "\r\n";

        $baseCryptos = BaseConstants::BASE_CRYPTOS;
        // Create a query builder to fetch only the securities with tickers in BASE_CRYPTOS
        $queryBuilder = $this->entityManager->getRepository(Security::class)->createQueryBuilder('s');

        // Add the condition to filter the tickers based on the BASE_CRYPTOS array
        $queryBuilder->where($queryBuilder->expr()->in('s.ticker', ':baseCryptos'))
            ->setParameter('baseCryptos', $baseCryptos);

        // Execute the query
        $securities = $queryBuilder->getQuery()->getResult();
        $marketIndex = $this->entityManager->getRepository(Security::class)->findOneBy(['ticker' => $this->getTicker()]);
        $bitcoin = $this->entityManager->getRepository(Security::class)->findOneBy(['ticker' => self::MAIN_CRYPTO]);

        if(!$marketIndex)
        {
            $marketIndex = new Security();
            $marketIndex->setTicker($this->getTicker());
            $marketIndex->setIsCrypto(false);
            $marketIndex->setIsForex(false);
        }

        $startDate = $bitcoin->getFirstCandleStick()->getDate()->format('Y-m-d');
        $date = new DateTime($startDate);
        $endDate = $bitcoin->getLastCandleStick()->getDate()->format('Y-m-d');
        $endDate = new DateTime($endDate);


        $previousPrice = null;
        while($date < $endDate)
        {
            $sumOfCagr = 0;
            $validCryptosCount = 0;
            $totalVolume = 0;
            foreach ($securities as $crypto) {
                /** @var Security $crypto */
                $candleStick = $crypto->getExactCandleStickByDate($date);

                if(!$candleStick)
                    continue;

                /** @var CandleStick $candleStick */
                $openPrice = $candleStick->getOpenPrice();
                $closePrice = $candleStick->getClosePrice();

                if($openPrice && $closePrice)
                {
                    $sumOfCagr += ($closePrice / $openPrice) - 1;
                    $validCryptosCount++;
                    $totalVolume += $candleStick->getVolume();
                }
            }
            $candleStick = $marketIndex->getExactCandleStickByDate($date);

            if($validCryptosCount && $previousPrice)
            {
                if(!$candleStick)
                    $candleStick = new CandleStick();
                $closePrice = $previousPrice * (1 + $sumOfCagr / $validCryptosCount);
                $candleStick->setClosePrice($closePrice);
                $candleStick->setOpenPrice($previousPrice);
                $candleStick->setLowestPrice(min($previousPrice, $closePrice));
                $candleStick->setHighestPrice(max($previousPrice, $closePrice));
                $candleStick->setVolume($totalVolume);



                $candleDate = clone $date;
                $candleStick->setDate($candleDate);

                $marketIndex->addCandleStick($candleStick);
                $this->entityManager->persist($candleStick);
                $previousPrice = $closePrice;
            }
            else {
                if(!$candleStick)
                    $candleStick = new CandleStick();
                $previousPrice = 1;
                $candleStick->setClosePrice($previousPrice);
                $candleStick->setLowestPrice($previousPrice);
                $candleStick->setHighestPrice($previousPrice);
                $candleStick->setOpenPrice($previousPrice);
                $candleStick->setVolume($totalVolume);


                $candleDate = clone $date;
                $candleStick->setDate($candleDate);

                $marketIndex->addCandleStick($candleStick);
                $this->entityManager->persist($candleStick);
            }

            $this->entityManager->persist($marketIndex);

            $date->modify('+1 day');
        }

        $this->entityManager->flush();
    }

    public function findMostBearishBullishDayDatesInHistory(int $topN = 10, bool $bearish = true)
    {
        $candleSticks = $this->nasdaq2000Data->getCandleSticks();
        $data = [];
        $previousCandleStick = null;
        foreach ($candleSticks as $candleStick) {
            if(!$previousCandleStick)
            {
                $previousCandleStick = $candleStick;
                continue;
            }

            $startDate = $previousCandleStick->getDate();
            $endDate = $candleStick->getDate();

            $cagr = $this->getCagrOfDates($startDate, $endDate);

            $data['DATE'][] = $candleStick->getDate()->format('Y-m-d');
            $data['CAGR'][] = $cagr;

            $previousCandleStick = $candleStick;
        }

        $sortOrder = $bearish ? SORT_ASC : SORT_DESC;
        // Sort by CAGR descending
        array_multisort($data['CAGR'], $sortOrder, $data['DATE']);

        $this->printInformationAboutHistoricalCagrDays($data, $topN);
    }

    public function findMostBearishBullishDatesIntervalInHistory(int $topN = 10, string $interval = 'month', bool $bearish = true)
    {
        $startDate = $this->nasdaq2000Data->getFirstCandleStick()->getDate()->format('Y-m-d');
        $date = new DateTime($startDate);
        $endDate = $this->nasdaq2000Data->getLastCandleStick()->getDate()->format('Y-m-d');
        $endDate = new DateTime($endDate);

        $previousCandleStick = null;
        while($date < $endDate)
        {
            $candleStick = $this->nasdaq2000Data->getCandleStickByDate($date);
            if(!$previousCandleStick)
            {
                $previousCandleStick = $candleStick;
                $date->modify('+1 ' . $interval); 
                continue;
            }

            $previousDate = $previousCandleStick->getDate();
            $cagr = $this->getCagrOfDates($previousDate, $date);

            $data['START_DATE'][] = $previousCandleStick->getDate()->format('Y-m-d');
            $data['END_DATE'][] = $candleStick->getDate()->format('Y-m-d');
            $data['CAGR'][] = $cagr;

            $previousCandleStick = $candleStick;
            $date->modify('+1 ' . $interval); 
        }

        $sortOrder = $bearish ? SORT_ASC : SORT_DESC;
        // Sort by CAGR descending
        array_multisort($data['CAGR'], $sortOrder, $data['START_DATE'], $data['END_DATE']);

        $this->printInformationAboutHistoricalCagrDateInterval($data, $topN);
    }

    private function printInformationAboutHistoricalCagrDateInterval($data, int $topN)
    {
        // Determine column widths for proper alignment
        $numberLength = 4; // Fixed length for the 'No.' column
        $startDateLength = max(array_map('strlen', $data['START_DATE'])) + 2;
        $endDateLength = max(array_map('strlen', $data['END_DATE'])) + 2;
        $cagrLength = max(array_map(function ($value) {
            return strlen(number_format($value, 2)); // Format to two decimal places
        }, $data['CAGR'])) + 2;
    
        // Print the header
        echo str_pad('No.', $numberLength) 
            . str_pad('START_DATE', $startDateLength) 
            . str_pad('END_DATE', $endDateLength) 
            . str_pad('CAGR', $cagrLength) . PHP_EOL;
    
        // Print a separator line
        echo str_repeat('-', $numberLength + $startDateLength + $endDateLength + $cagrLength) . PHP_EOL;
    
        // Print each row of data
        $counter = 0;
        foreach ($data['START_DATE'] as $index => $startDate) {
            echo str_pad(++$counter, $numberLength) 
                . str_pad($startDate, $startDateLength) 
                . str_pad($data['END_DATE'][$index], $endDateLength) 
                . str_pad(number_format($data['CAGR'][$index], 2), $cagrLength) 
                . PHP_EOL;

            if($counter >= $topN)
                break;
        }
    }

    private function printInformationAboutHistoricalCagrDays($data, int $topN)
    {
        // Determine the maximum lengths for alignment
        $numberLength = strlen((string)count($data['DATE'])) + 2; // Length for 'No.' column
        $dateLength = max(array_map('strlen', $data['DATE'])) + 2; // Length for 'DATE' column
        $cagrLength = max(array_map(function ($value) {
            return strlen(number_format($value, 2)); // Format to two decimal places
        }, $data['CAGR'])) + 2; // Length for 'CAGR' column
    
        // Print the header row
        echo str_pad('No.', $numberLength) . str_pad('DATE', $dateLength) . str_pad('CAGR', $cagrLength) . PHP_EOL;
        echo str_repeat('-', $numberLength + $dateLength + $cagrLength) . PHP_EOL;
    
        $counter = 0;
        // Print the rows
        foreach ($data['DATE'] as $index => $date) {
            $number = $index + 1; // Row number
            echo str_pad($number, $numberLength) 
                . str_pad($date, $dateLength) 
                . str_pad(number_format($data['CAGR'][$index], 2), $cagrLength) 
                . PHP_EOL;

            $counter++;
            if($counter >= $topN)
                break;
        }
    }

    public function getTicker() : string
    {
        return BaseConstants::CRYPTO_MARKET_TICKER;
    }
}