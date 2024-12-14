<?php 

namespace App\Interface;

use DateTime;

interface MarketIndexInterface{

    public function getCagrOfDates(DateTime $startDate, DateTime $endDate, bool $isRecursion = false) : ?float;
    public function updateMarketData();
    public function findMostBearishBullishDayDatesInHistory(int $topN = 10, bool $bearish = true);
    public function findMostBearishBullishDatesIntervalInHistory(int $topN = 10, string $interval = 'month', bool $bearish = true);
    public function getTicker() : string;
}
