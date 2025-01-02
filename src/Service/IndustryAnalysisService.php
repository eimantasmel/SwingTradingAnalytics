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
    private const MIN_AMOUNT_OF_STOCKS_INDUSTRY_HAS = 3;

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

    public function getBottomTrendingIndustry(DateTime $date) : string 
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
            $finalCandleStick = $stock->getCandleStickByDate($date);
            $initialCandleStick = $stock->getCandleStickByDate($earliestDate);

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
        $trendingIndustry = "No Industry";

        foreach ($data as $key => $industryData) {
            if($industryData['cagr'] > $highestCAGR && $industryData['amount'] > self::MIN_AMOUNT_OF_STOCKS_INDUSTRY_HAS)
            {
                $trendingIndustry = $key;
                $highestCAGR = $industryData['cagr'];
            }
        }

        // echo "Highest CAGR: " . $highestCAGR . "\r\n";

        if($highestCAGR <= 0)
            $trendingIndustry = "No Industry";


        return $trendingIndustry;
    }

    private function getLowestIndustry($data) : string
    {
        $lowestCAGR = PHP_INT_MAX;
        $trendingIndustry = "No Industry";

        foreach ($data as $key => $industryData) {
            if($industryData['cagr'] < $lowestCAGR && $industryData['amount'] > self::MIN_AMOUNT_OF_STOCKS_INDUSTRY_HAS)
            {
                $trendingIndustry = $key;
                $lowestCAGR = $industryData['cagr'];
            }
        }

        // echo "Lowest  CAGR: " . $lowestCagr . "\r\n";

        if($lowestCAGR >= 0)
            $trendingIndustry = "No Industry";

        return $trendingIndustry;
    }
}