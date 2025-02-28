<?php 

namespace App\Interface;

use App\Entity\Security;
use DateTime;

interface SwingTradingStrategyInterface
{
    /**
     * This method should return:
     * the amount of trades, 
     * the winn percentage
     * the losses percentage.
     * the final trading capital
     * as well is should return the separate assoc array about all trades information
     * it would be like date_of_start_trade, ticker, traded sum, stop loss, exit loss
     * @param Security[] $securities
     * return this data in the assoc array.
     */
    public function getSimulationData(string $startDate, 
                                    string $endDate,
                                    float $tradingCapital,
                                    array $securities
                                     ) : array;

    public function canITrade(Security $security, DateTime $tradingDate) : bool;

    public function shouldIExit(Security $security, $stopLoss, $sharesAmount, DateTime $tradingDate, float $enterPrice, array $nextCandleSticks) : bool;

}
