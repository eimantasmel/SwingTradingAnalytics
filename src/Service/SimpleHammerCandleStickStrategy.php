<?php

namespace App\Service;

use App\Entity\Security;
use App\Entity\CandleStick;
use App\Interface\SwingTradingStrategyInterface;
use App\Constants\BaseConstants;
use App\Service\CandleSticksCacheService;

use DateTime;

class SimpleHammerCandleStickStrategy implements SwingTradingStrategyInterface
{
    private const MIN_LOWER_SHADOW_TO_BODY_RATIO = 3;
    private const MAX_BODY_TO_RANGE_RATIO = 0.3;
    private const AMOUNT_OF_PREVIOUS_CANDLESTICKS = 5;
    private const AMOUNT_OF_NEXT_CANDLESTICKS = 100;
    private const MIN_VOLUME = 300_000;
    private const CAPITAL_RISK = 0.01;
    private const RISK_REWARD_RATIO = 1;
    private const TRADE_FEE = 1;
    private const MAX_AMOUNT_TRADES_PER_DAY = 5;

    private array $trade_information = [];
    private array $results = [];
    private CandleSticksCacheService $candleSticksCacheService;


    public function __construct(CandleSticksCacheService $candleSticksCacheService) {
        $this->candleSticksCacheService = $candleSticksCacheService;
    }

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
                                     ) : array
    {
        echo "NEW TRADE CYCLE -------------------------------------------------------\n\r";

        $this->results = [];
        $this->results[BaseConstants::AMOUNT_OF_TRADES] = 0;
        $startDate = new DateTime($startDate);
        $endDate = new DateTime($endDate);

        while($startDate < $endDate)
        {
            // It's skips weekends because in our database there's only few cryptos.
            if($startDate->format('N') >= 6)
                continue;
            $tradingCapital = $this->getTradingCapitalAfterDay($startDate, $securities, $tradingCapital);
            $startDate->modify('+1 day');
        }

        $this->results[BaseConstants::FINAL_TRADING_CAPITAL] = $tradingCapital;

        return $this->results;
    }

    private function getTradingCapitalAfterDay(DateTime $tradingDate, array $securities, float $tradingCapital)
    {
        shuffle($securities);
        echo "-----------------NEW DAY---------------" . "\n\r";
        $tradesCounter = 0;
        foreach ($securities as $security) {
            // $lastCandleSticks = $this->getLastNCandleSticks($security, $tradingDate);
            $lastCandleSticks = $security->getLastNCandleSticks($tradingDate, self::AMOUNT_OF_PREVIOUS_CANDLESTICKS);
            echo $security->getTicker() . "\n\r";
            if($this->isSecurityEligibleForTrading($lastCandleSticks, $security, $tradingDate))
            {
                echo "-----------------FOUND---------------" . "\n\r";
                $lastCandleStick = $lastCandleSticks[count($lastCandleSticks) - 1];
                $enterPrice = (float)$lastCandleStick->getClosePrice();

                $averageCandleStickRange = $this->getAverageCandleStickRange($lastCandleSticks);
                $stopLoss = $enterPrice - $averageCandleStickRange;
                $takeProfit = $enterPrice + $averageCandleStickRange * self::RISK_REWARD_RATIO;
                $tradeCapital = $this->getTradeCapital($tradingCapital, $stopLoss, $enterPrice);
                // Checks whether do we have enough money to afford this trade
                if(!$tradeCapital)
                    continue;

                $sharesAmount = $this->getSharesAmount($tradingCapital, $stopLoss, $enterPrice);

                $tradingCapitalAfterTrade = $this->getProfit($security, $stopLoss, $takeProfit, $sharesAmount, $tradingDate);
                $tradingCapital = $tradingCapital - $tradeCapital + $tradingCapitalAfterTrade;

                if($tradingCapitalAfterTrade > $tradeCapital)
                    $this->addTradingDataInformation(BaseConstants::IS_WINNER, true);
                else    
                    $this->addTradingDataInformation(BaseConstants::IS_WINNER, false);


                $this->addTradingDataInformation(BaseConstants::IS_WINNER, true);
                $this->addTradingDataInformation(BaseConstants::TRADE_ENTER_PRICE, $enterPrice);
                $this->addTradingDataInformation(BaseConstants::TRADING_CAPITAL, $tradingCapital);
                $this->results[BaseConstants::TRADES_INFORMATION][] = $this->trade_information;
                $this->results[BaseConstants::AMOUNT_OF_TRADES]++;

                if(++$tradesCounter >= self::MAX_AMOUNT_TRADES_PER_DAY)
                    return $tradingCapital;
            }
        }

        return $tradingCapital;
    }

    private function getProfit($security, $stopLoss, $takeProfit, $sharesAmount, DateTime $tradingDate) : float 
    {
        $this->addTradingDataInformation(BaseConstants::TRADE_DATE, $tradingDate->format('Y-m-d'));
        $this->addTradingDataInformation(BaseConstants::TRADE_SECURITY_TICKER, $security->getTicker());
        $this->addTradingDataInformation(BaseConstants::TRADE_STOP_LOSS_PRICE, $stopLoss);
        $this->addTradingDataInformation(BaseConstants::TRADE_TAKE_PROFIT_PRICE, $takeProfit);

        $nextCandleSticks = $security->getNextNCandleSticks($tradingDate, self::AMOUNT_OF_NEXT_CANDLESTICKS);
        foreach ($nextCandleSticks as $candleStick) {
            /** @var CandleStick $candleStick */
            $closePrice = $candleStick->getClosePrice();
            $lowersPrice = $candleStick->getLowestPrice();
            $highestPrice = $candleStick->getHighestPrice();
            $exitDate = $candleStick->getDate()->format('Y-m-d');

            if($closePrice >= $takeProfit || $closePrice <= $stopLoss)
            {
                $this->addTradingDataInformation(BaseConstants::TRADE_EXIT_PRICE, $closePrice);
                $this->addTradingDataInformation(BaseConstants::EXIT_DATE, $exitDate);

                return $closePrice * $sharesAmount - self::TRADE_FEE;
            }

            if($lowersPrice <= $stopLoss)
            {
                $this->addTradingDataInformation(BaseConstants::TRADE_EXIT_PRICE, $stopLoss);
                $this->addTradingDataInformation(BaseConstants::EXIT_DATE, $exitDate);

                return $lowersPrice * $sharesAmount - self::TRADE_FEE;
            }

            if($highestPrice >= $takeProfit)
            {
                $this->addTradingDataInformation(BaseConstants::TRADE_EXIT_PRICE, $takeProfit);
                $this->addTradingDataInformation(BaseConstants::EXIT_DATE, $exitDate);

                return $highestPrice * $sharesAmount - self::TRADE_FEE;
            }
        }

        $exitDate = $candleStick->getDate()->format('Y-m-d');
        $this->addTradingDataInformation(BaseConstants::EXIT_DATE, $exitDate);

        return $candleStick->getClosePrice() * $sharesAmount - self::TRADE_FEE;
    }

    private function isSecurityEligibleForTrading(array $lastCandleSticks, Security $security, DateTime $tradingDate) : bool
    {
        $lastCandleStick = $lastCandleSticks[count($lastCandleSticks) - 1];
        $volume = $lastCandleStick->getVolume();

        if($this->isCandlestickStrongHammerPattern($lastCandleStick) 
            && $volume > self::MIN_VOLUME
            && $lastCandleStick->getClosePrice() != 0 
            && $lastCandleStick->getClosePrice() != 1 
          )
            return true;

        return false;
    }

    private function isCandlestickStrongHammerPattern(CandleStick $candleStick) : bool
    {
        $openPrice = $candleStick->getOpenPrice();
        $highestPrice = $candleStick->getHighestPrice();
        $lowestPrice = $candleStick->getLowestPrice();
        $closePrice = $candleStick->getClosePrice();
    
        // Calculate body size and shadow sizes
        $bodySize = abs($closePrice - $openPrice);
        $lowerShadowSize = $openPrice > $closePrice
            ? $openPrice - $lowestPrice
            : $closePrice - $lowestPrice;
        $upperShadowSize = $highestPrice - max($openPrice, $closePrice);
    
        // Define thresholds for a strong hammer
        $totalRange = $highestPrice - $lowestPrice;

        if(!$totalRange || !$bodySize)
            return false;

        $bodyToRangeRatio = $bodySize / $totalRange;
        $lowerShadowToBodyRatio = $lowerShadowSize / $bodySize;
    
        // Check if the candlestick meets the criteria for a strong hammer
        if (
            $bodyToRangeRatio < self::MAX_BODY_TO_RANGE_RATIO &&              // Body should be small relative to the total range
            $lowerShadowToBodyRatio >= self::MIN_LOWER_SHADOW_TO_BODY_RATIO &&          // Lower shadow should be at least twice the body size
            $upperShadowSize < $bodySize &&          // Upper shadow should be smaller than the body
            $closePrice > $openPrice                 // Close should be above or close to the open price
        ) {
            return true;
        }
    
        return false;
    }

    private function getTradeCapital($tradingCapital, $stopLoss, $enterPrice)
    {
        $riskCapital = $tradingCapital * self::CAPITAL_RISK;
        $sharesAmount = $riskCapital / ($enterPrice - $stopLoss);
        if($enterPrice * $sharesAmount > $tradingCapital)
            return false;

        return $sharesAmount * $enterPrice;
    }

    private function getSharesAmount($tradingCapital, $stopLoss, $enterPrice) : int
    {
        $riskCapital = $tradingCapital * self::CAPITAL_RISK;
        $sharesAmount = (int)($riskCapital / ($enterPrice - $stopLoss));

        return $sharesAmount;
    }

    /**
     * @param CandleStick[] $candlesticks
     */
    private function getAverageCandleStickRange(array $candleSticks) : float
    {
        $sum = 0;
        foreach ($candleSticks as $candleStick) {
            $highestPrice = $candleStick->getHighestPrice();
            $lowestPrice = $candleStick->getLowestPrice();
            $sum += $highestPrice - $lowestPrice;
        }

        return $sum / count($candleSticks);
    }
    
    private function addTradingDataInformation($key, $value)
    {
        $this->trade_information[$key] = $value;
    }




    /** That shit tried because i hope to implement that with cache service, but it's even slower.
     * @deprecated
     * ------------------------------------------------------------------------------------------------------------------- */

    private function getLastNCandleSticks(Security $security, DateTime $tradingDate)
    {
        $candleSticks = $this->candleSticksCacheService->getCandlesticksBySecurityId($security->getId());
        foreach ($candleSticks as $candleStick) {
            $date = $candleStick->getDate();
            if($tradingDate->diff($date)->days < self::AMOUNT_OF_PREVIOUS_CANDLESTICKS && $tradingDate->diff($date)->days >= 0)
            {
                $lastCandleSticks[] = $candleStick;
            }
        }

        return $lastCandleSticks;
    }

    /**
     * @deprecated
     */
    private function getNextNCandleSticks(Security $security, DateTime $tradingDate) : array
    {
        $nextCandleSticks = [];
        $candleSticks = $this->candleSticksCacheService->getCandlesticksBySecurityId($security->getId());
        foreach ($candleSticks as $candleStick) {
            $date = $candleStick->getDate();
            if($date->diff($tradingDate)->days < self::AMOUNT_OF_NEXT_CANDLESTICKS && $date->diff($tradingDate)->days >= 0)
            {
                $nextCandleSticks[] = $candleStick;
            }
        }

        return $nextCandleSticks;
    }
}