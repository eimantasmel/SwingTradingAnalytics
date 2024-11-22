<?php

namespace App\Service;

use App\Entity\Security;
use App\Entity\CandleStick;
use App\Interface\SwingTradingStrategyInterface;
use App\Constants\BaseConstants;
use App\Service\CandleSticksCacheService;

use DateTime;

class MovingAverageCrossoverStrategy implements SwingTradingStrategyInterface
{
    private const MIN_LOWER_SHADOW_TO_BODY_RATIO = 3;
    private const MIN_TOTAL_RANGE_RATIO = 1.5;
    private const MIN_TOTAL_VOLUME_RATIO = 1;

    private const MIN_AMOUNT_OF_MONEY = 20;

    private const AMOUNT_OF_PREVIOUS_CANDLESTICKS = 210;
    private const AMOUNT_OF_NEXT_CANDLESTICKS = 100;
    private const MIN_VOLUME = 300_000;
    private const CAPITAL_RISK = 0.01;
    private const RISK_REWARD_RATIO = 4;    // i got only 29 percent of course with rr ratio i would get even less.
    private const TRADE_FEE = 1;
    private const MAX_AMOUNT_TRADES_PER_DAY = 5;
    private const AMOUNT_CANDLESTICK_TO_FORM_STOP_LOSS = 3;
    private const MIN_BODY_TO_RANGE_RATIO = 0.9;
    private const MIN_TOTAL_RANGE_TO_AVERAGE_CANDLE_STICK_RANGE_RATIO = 1.2;
    

    private const MIN_PRICE = 0.1;
    private const MAX_PRICE = 700;


    private array $trade_information = [];
    private array $results = [];

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
            {
                $startDate->modify('+1 day');
                continue;
            }
            $tradingCapital = $this->getTradingCapitalAfterDay($startDate, $securities, $tradingCapital);
            $startDate->modify('+1 day');

            if($tradingCapital < self::MIN_AMOUNT_OF_MONEY)
                break;
        }

        $this->results[BaseConstants::FINAL_TRADING_CAPITAL] = $tradingCapital;

        return $this->results;
    }

    private function getTradingCapitalAfterDay(DateTime $tradingDate, array $securities, float $tradingCapital)
    {
        shuffle($securities);
        $tradesCounter = 0;
        foreach ($securities as $security) {
            // $lastCandleSticks = $this->getLastNCandleSticks($security, $tradingDate);
            $lastCandleSticks = $security->getLastNCandleSticks($tradingDate, self::AMOUNT_OF_PREVIOUS_CANDLESTICKS);
            echo "Date: " . $tradingDate->format('Y-m-d') . $security->getTicker() . "\n\r";
            if($this->isSecurityEligibleForTrading($lastCandleSticks, $security, $tradingDate))
            {
                $lastCandleStick = $lastCandleSticks[count($lastCandleSticks) - 1];
                $enterPrice = (float)$lastCandleStick->getClosePrice();

                $averageCandleStickRange = $this->getAverageCandleStickRange($lastCandleSticks);
                $stopLoss = $enterPrice - $averageCandleStickRange * self:: AMOUNT_CANDLESTICK_TO_FORM_STOP_LOSS;
                $takeProfit = $enterPrice + $averageCandleStickRange * self:: AMOUNT_CANDLESTICK_TO_FORM_STOP_LOSS * self::RISK_REWARD_RATIO;
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


                $this->addTradingDataInformation(BaseConstants::TRADE_ENTER_PRICE, $enterPrice);
                $this->addTradingDataInformation(BaseConstants::TRADING_CAPITAL, $tradingCapital);
                $this->results[BaseConstants::TRADES_INFORMATION][] = $this->trade_information;
                $this->results[BaseConstants::AMOUNT_OF_TRADES]++;

                if(++$tradesCounter >= self::MAX_AMOUNT_TRADES_PER_DAY || $tradingCapital < self::MIN_AMOUNT_OF_MONEY)
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
            if($candleStick->getDate() == $tradingDate)
                continue;

            /** @var CandleStick $candleStick */
            $closePrice = $candleStick->getClosePrice();
            $lowestPrice = $candleStick->getLowestPrice();
            $highestPrice = $candleStick->getHighestPrice();
            $exitDate = $candleStick->getDate()->format('Y-m-d');

            if($lowestPrice <= $stopLoss)
            {
                $this->addTradingDataInformation(BaseConstants::TRADE_EXIT_PRICE, $lowestPrice);
                $this->addTradingDataInformation(BaseConstants::EXIT_DATE, $exitDate);

                return $lowestPrice * $sharesAmount - self::TRADE_FEE;
            }

            if($highestPrice >= $takeProfit)
            {
                $this->addTradingDataInformation(BaseConstants::TRADE_EXIT_PRICE, $highestPrice);
                $this->addTradingDataInformation(BaseConstants::EXIT_DATE, $exitDate);

                return $highestPrice * $sharesAmount - self::TRADE_FEE;
            }

            if($closePrice >= $takeProfit || $closePrice <= $stopLoss)
            {
                $this->addTradingDataInformation(BaseConstants::TRADE_EXIT_PRICE, $closePrice);
                $this->addTradingDataInformation(BaseConstants::EXIT_DATE, $exitDate);

                return $closePrice * $sharesAmount - self::TRADE_FEE;
            }
        }

        $exitDate = $candleStick->getDate()->format('Y-m-d');
        $closePrice = $candleStick->getClosePrice();
        $this->addTradingDataInformation(BaseConstants::EXIT_DATE, $exitDate);
        $this->addTradingDataInformation(BaseConstants::TRADE_EXIT_PRICE, $closePrice);

        return $candleStick->getClosePrice() * $sharesAmount - self::TRADE_FEE;
    }

    private function isSecurityEligibleForTrading(array $lastCandleSticks, Security $security, DateTime $tradingDate) : bool
    {
        $lastCandleStick = $lastCandleSticks[count($lastCandleSticks) - 1];
        $volume = $lastCandleStick->getVolume();

        $lastCandleSticksWithoutLast = array_slice($lastCandleSticks, 0, -1);


        $prices = $this->extractClosingPricesFromCandlesticks($lastCandleSticks);
        $pricesBeforeLastCandle = $this->extractClosingPricesFromCandlesticks($lastCandleSticksWithoutLast);
        

        $sma50After = $this->calculateSMA($prices, 50);
        $sma200After = $this->calculateSMA($prices, 200);

        $sma50Before= $this->calculateSMA($pricesBeforeLastCandle, 50);
        $sma200Before = $this->calculateSMA($pricesBeforeLastCandle, 200);



        if(($this->isCandlestickStrongHammerPattern($lastCandleStick, $lastCandleSticks) 
                || $this->isCandleStickStrongBullish($lastCandleStick, $lastCandleSticks) 
                || $this->isThereAStrongVolume($lastCandleStick, $lastCandleSticks) 
            )
            && ($volume > self::MIN_VOLUME || $security->getIsForex())
            && $lastCandleStick->getClosePrice() != 0 
            && $lastCandleStick->getClosePrice() != 1 
            && $lastCandleStick->getClosePrice() > self::MIN_PRICE
            && $lastCandleStick->getClosePrice() < self::MAX_PRICE
            && $sma200Before > $sma50Before
            && $sma200After < $sma50After
          )
            return true;

        return false;
    }

    private function isThereAStrongVolume(CandleStick $candleStick, array $lastCandleSticks) : bool
    {
        $volume = $candleStick->getVolume();
        $averageCandleStickVolume = $this->getAverageCandleStickVolume($lastCandleSticks);

        if($volume > $averageCandleStickVolume * 2)
        {
            return true;
        }

        return false;
    }

    private function isCandlestickStrongHammerPattern(CandleStick $candleStick, array $lastCandleSticks) : bool
    {
        $openPrice = $candleStick->getOpenPrice();
        $highestPrice = $candleStick->getHighestPrice();
        $lowestPrice = $candleStick->getLowestPrice();
        $closePrice = $candleStick->getClosePrice();
        $volume = $candleStick->getVolume();

        $averageCandleStickRange = $this->getAverageCandleStickRange($lastCandleSticks);
        $averageCandleStickVolume = $this->getAverageCandleStickVolume($lastCandleSticks);

    
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

        $lowerShadowToBodyRatio = $lowerShadowSize / $bodySize;
    
        // Check if the candlestick meets the criteria for a strong hammer
        if (
            $lowerShadowToBodyRatio >= self::MIN_LOWER_SHADOW_TO_BODY_RATIO &&          // Lower shadow should be at least twice the body size
            $upperShadowSize < $bodySize &&          // Upper shadow should be smaller than the body
            $closePrice > $openPrice     &&            // Close should be above or close to the open price
            $totalRange > $averageCandleStickRange * self::MIN_TOTAL_RANGE_RATIO &&
            $volume > $averageCandleStickVolume * self::MIN_TOTAL_VOLUME_RATIO
        ) {
            return true;
        }
    
        return false;
    }

    /**
     * TODO: couples updates to improve performance
     * you will enter trade if the next candlestick high is higher than currenct candle stick high
     * stop loss should be below previous candlestick low.
     */

    private function isCandleStickStrongBullish(CandleStick $candleStick, array $lastCandleSticks) : bool
    {
        $openPrice = $candleStick->getOpenPrice();
        $highestPrice = $candleStick->getHighestPrice();
        $lowestPrice = $candleStick->getLowestPrice();
        $closePrice = $candleStick->getClosePrice();

        $totalRange = $highestPrice - $lowestPrice;
        $bodySize = abs($closePrice - $openPrice);

        $averageCandleStickRange = $this->getAverageCandleStickRange($lastCandleSticks);

        if(!$totalRange)
            return false;

        $bodyToRangeRatio = $bodySize / $totalRange;

        $tatalRangeToAverageCandleStickRangeRatio = $totalRange / $averageCandleStickRange;


        if($closePrice > $openPrice 
            && $bodyToRangeRatio > self::MIN_BODY_TO_RANGE_RATIO
            && $tatalRangeToAverageCandleStickRangeRatio > self::MIN_TOTAL_RANGE_TO_AVERAGE_CANDLE_STICK_RANGE_RATIO)
        {
            return true;
        }

        return false;
    }

    private function getTradeCapital($tradingCapital, $stopLoss, $enterPrice)
    {
        $sharesAmount = $this->getSharesAmount($tradingCapital, $stopLoss, $enterPrice);
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

    /**
     * @param CandleStick[] $candlesticks
     */
    private function getAverageCandleStickVolume(array $candleSticks) : float
    {
        $sum = 0;
        foreach ($candleSticks as $candleStick) {
            $volume = $candleStick->getVolume();
            $sum += $volume;
        }

        return $sum / count($candleSticks);
    }
    
    private function addTradingDataInformation($key, $value)
    {
        $this->trade_information[$key] = $value;
    }

    private function calculateSMA(array $prices, int $n = 50): ?float {
        // Check if the number of prices is sufficient for the calculation
        if (count($prices) < $n) {
            return null; // Not enough data points to calculate the SMA
        }
    
        // Calculate the sum of the last N prices
        $sum = array_sum(array_slice($prices, -$n));
    
        // Calculate the SMA
        $sma = $sum / $n;
    
        return $sma;
    }

    private function extractClosingPricesFromCandlesticks(array $candleSticks) : array
    {
        $prices = [];
        foreach ($candleSticks as $candleStick) {
            $prices[] = (float) $candleStick->getClosePrice();
        }

        return $prices;
    }
}