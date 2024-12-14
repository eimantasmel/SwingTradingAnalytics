<?php

namespace App\Service;

use App\Constants\Industries;
use Doctrine\ORM\EntityManagerInterface;
use App\Entity\Security;
use App\Entity\CandleStick;
use DateTime;


/** Works too slowly something is wrong.  */
class IndustryAnalysisService
{
    private const MIN_AMOUNT_OF_STOCKS_INDUSTRY_HAS = 5;

    private $entityManager;
    private array $stocks;

    public function __construct(EntityManagerInterface $entityManager)
    {
        $this->entityManager = $entityManager;
        $this->stocks = $this->entityManager->getRepository(Security::class)->findBy([
            'isForex' => 0,
            'isCrypto' => 0,
        ]);
    }

    public function getTopTrendingIndustry(DateTime $date) : string 
    {
        $industries = $this->getIndustriesWithCAGR($date);

        return $this->getHighestIndustry($industries);
    }

    public function getLastTrendingIndustry(DateTime $date) : string 
    {
        $industries = $this->getIndustriesWithCAGR($date);

        return $this->getLowestIndustry($industries);
    }

    private function getIndustriesWithCAGR($date)
    {
        $data = [];
        foreach ($this->stocks as $stock) {
            $earliestDate = clone $date;
            $earliestDate->modify('-1 month');

            /** @var Security $stock */
            /** TODO: This one lasts way longer compared to second one.*/
            $initialCandleStick = $stock->getCandleStickByDate($earliestDate);
            $finalCandleStick = $stock->getCandleStickByDate($date);

            $cagr = ((float)$finalCandleStick->getClosePrice() / (float)($initialCandleStick->getClosePrice())) - 1;
            $industry = Industries::findClosestIndustry($stock->getIndustry());

            $data[$industry][] = $cagr;
        }

        return $this->calculateAverageCAGROfEachIndustry($data);
    }

    private function calculateAverageCAGROfEachIndustry($data)
    {
        $updatedData = [];
        foreach ($data as $key => $industryValues) {
            $updatedData[$key]['cagr'] = array_sum($industryValues) / count($industryValues);
            $updatedData[$key]['amount'] = count($industryValues);

        }

        return $updatedData;
    }

    private function getHighestIndustry($data) : string
    {
        $highestCAGR = 0;
        $trendingIndustry = null;

        foreach ($data as $key => $industryData) {
            if($industryData['cagr'] > $highestCAGR && $industryData['amount'] > self::MIN_AMOUNT_OF_STOCKS_INDUSTRY_HAS)
            {
                $trendingIndustry = $key;
                $highestCAGR = $industryData['cagr'];
            }
        }

        return $trendingIndustry;
    }

    private function getLowestIndustry($data) : string
    {
        $lowestCAGR = PHP_INT_MAX;
        $trendingIndustry = null;

        foreach ($data as $key => $industryData) {
            if($industryData['cagr'] < $lowestCAGR && $industryData['amount'] > self::MIN_AMOUNT_OF_STOCKS_INDUSTRY_HAS)
            {
                $trendingIndustry = $key;
                $lowestCAGR = $industryData['cagr'];
            }
        }

        return $trendingIndustry;
    }
}