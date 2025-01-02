<?php

namespace App\Service;

use DateTime;
use App\Constants\BaseConstants;
use Doctrine\ORM\EntityManagerInterface;
use App\Entity\Security;
use App\Entity\CandleStick;
use App\Service\YahooWebScrapService;
use App\Interface\MarketIndexInterface;

class JapaneseMarketIndexService implements MarketIndexInterface
{
    private EntityManagerInterface $entityManager;
    private Security $nasdaq2000Data;
    private YahooWebScrapService $yahooWebScrapService;

    public function __construct(EntityManagerInterface $entityManager,
                                YahooWebScrapService $yahooWebScrapService) {
        $this->entityManager = $entityManager;
        $this->yahooWebScrapService = $yahooWebScrapService;
        $this->nasdaq2000Data = $this->entityManager
        ->getRepository(Security::class)
        ->findOneBy(['ticker' => BaseConstants::JAPANESE_MARKET_TICKER]);
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
        $lastCandleStick = $this->nasdaq2000Data->getLastCandleStick();
        $lastYear = $lastCandleStick->getDate()->format("Y");
        $date = new DateTime();

        if($lastCandleStick->getDate()->diff($date)->days == 0)
        {
            return ;
        }

        $data = $this
                    ->yahooWebScrapService
                    ->getStockDataByDatesByOlderDates(BaseConstants::JAPANESE_MARKET_TICKER, $lastYear);

 
        if(!$data['Open Price'][0])
        {
            echo "Something went wrong with retrieving nasdaq data \r\n";
            return null;
        }

        $index = 0;
        for ($i=0; $i < count($data['Volume']); $i++) { 
            $date = new DateTime(($data['Dates'][$i]));

            if($this->nasdaq2000Data->isDateExist($date) || !$data['Close Price'][$i])
                continue;

            $candleStick = new CandleStick();
            $candleStick->setVolume($data['Volume'][$i]);
            $candleStick->setOpenPrice($data['Open Price'][$i]);
            $candleStick->setHighestPrice($data['High Price'][$i]);
            $candleStick->setLowestPrice($data['Low Price'][$i]);
            $candleStick->setClosePrice($data['Close Price'][$i]);
            $candleStick->setDate($date);

            $this->nasdaq2000Data->addCandleStick($candleStick);
            $this->entityManager->persist($candleStick);

            if($index++ % 5 == 0)
            {
                $this->entityManager->flush();
            }
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
        return BaseConstants::JAPANESE_MARKET_TICKER;
    }
}