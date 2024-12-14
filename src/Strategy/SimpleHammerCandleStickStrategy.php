<?php

namespace App\Strategy;


use App\Entity\Security;
use App\Entity\CandleStick;
use App\Interface\SwingTradingStrategyInterface;
use App\Constants\BaseConstants;
use App\Service\TechnicalIndicatorsService;
use Doctrine\ORM\EntityManagerInterface;
use App\Interface\MarketIndexInterface;

use DateTime;
/**
 * NOTES:
 * @drabacks - it finds only few trades within 4-5 months
 * strongly depends on market structure.
 * win percentage over 107 57/107 = 53.2% 
 * would be interesting to see how much i would receive with kelly strategy.
 */
class SimpleHammerCandleStickStrategy implements SwingTradingStrategyInterface
{
    private const MIN_LOWER_SHADOW_TO_BODY_RATIO = 3;
    private const MIN_TOTAL_VOLUME_RATIO = 1.2;

    private const AMOUNT_OF_PREVIOUS_CANDLESTICKS = 450;
    private const AMOUNT_OF_NEXT_CANDLESTICKS = 100;
    private const MIN_VOLUME = 100_000;
    private const CAPITAL_RISK = 0.1;
    private const TRADE_FEE = 1;
    private const MAX_AMOUNT_TRADES_PER_DAY = 1;
    private const AMOUNT_OF_ATR5_FOR_STOP_LOSS = 3;

    private const MIN_AMOUNT_OF_MONEY = 20;

    private const MIN_AMOUNT_OF_CANDLESTICKS = 130;

    private const PYRAMIDING_TRADES_AMOUNT = 3;



    private const MIN_PRICE = 0.1;

    private array $trade_information = [];
    private array $results = [];
    private float $maxDrawdown;
    private float $highestCapitalValue;

    private MarketIndexInterface $marketIndex;
    private TechnicalIndicatorsService $technicalIndicatorsService;
    private EntityManagerInterface $entityManager;

    public function __construct(MarketIndexInterface $marketIndex,
                                TechnicalIndicatorsService $technicalIndicatorsService,
                                EntityManagerInterface $entityManager) {

        $this->marketIndex = $marketIndex;
        $this->technicalIndicatorsService = $technicalIndicatorsService;
        $this->entityManager = $entityManager;
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
        $this->results = [];
        $this->maxDrawdown = 0;
        $this->highestCapitalValue = $tradingCapital;
        $this->results[BaseConstants::HIGHEST_CAPITAL] = $tradingCapital;
        $this->results[BaseConstants::MAX_DRAWDOWN] = 0;
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

            $nasdaqIndex = $this->entityManager->getRepository(Security::class)->findOneBy(['ticker' => BaseConstants::NASDAQ_2000_TICKER]);
            $lastNasdaqMarketCandleSticks = $nasdaqIndex->getLastNCandleSticks($startDate, self::AMOUNT_OF_PREVIOUS_CANDLESTICKS);
            $lastNasdaqCandleStick = $this->getLastCandleStick($lastNasdaqMarketCandleSticks);
    
            $nasdaqMarketPrices = $this->extractClosingPricesFromCandlesticks($lastNasdaqMarketCandleSticks);
            $sma200 = $this->technicalIndicatorsService->calculateSMA($nasdaqMarketPrices, 200);

            // It means that crypto market has to be on the bull run.
            if($lastNasdaqCandleStick->getClosePrice() <= $sma200)
            {
                $startDate->modify('+1 day');
                continue;
            }

            $tradingCapitalBefore = $tradingCapital;
            $tradingCapital = $this->getTradingCapitalAfterDay($startDate, $securities, $tradingCapital);

            if($tradingCapitalBefore == $tradingCapital)
            {
                $startDate->modify("+1 day");
                continue;
            }

            $randomDateInterval = (int)mt_rand(2, 8);
            $startDate->modify("+{$randomDateInterval} days");


            if($tradingCapital > $this->highestCapitalValue)
            {
                $this->highestCapitalValue = $tradingCapital;
                $this->results[BaseConstants::HIGHEST_CAPITAL] = $tradingCapital;
            }

            if($tradingCapital / $this->highestCapitalValue - 1 < $this->maxDrawdown)
            {
                $this->maxDrawdown = $tradingCapital / $this->highestCapitalValue - 1;
                $this->results[BaseConstants::MAX_DRAWDOWN] = $this->maxDrawdown ;
            }

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
            if($security->getTicker() == BaseConstants::NASDAQ_2000_TICKER)
                continue;

            $lastCandleSticks = $security->getLastNCandleSticks($tradingDate, self::AMOUNT_OF_PREVIOUS_CANDLESTICKS);
            // echo "Date: " . $tradingDate->format('Y-m-d') . $security->getTicker() . "\n\r";
            if($this->isSecurityEligibleForTrading($lastCandleSticks, $tradingDate, $security))
            {
                $lastCandleStick = $lastCandleSticks[count($lastCandleSticks) - 1];
                $enterPrice = (float)$lastCandleStick->getClosePrice();



                $stopLoss = $this->trade_information[BaseConstants::TRADE_STOP_LOSS_PRICE];
                $tradeCapital = $this->getTradeCapital($tradingCapital, $stopLoss, $enterPrice);
                // Checks whether do we have enough money to afford this trade
                if(!$tradeCapital)
                    continue;

                $spread = $this->technicalIndicatorsService->calculateSpread($lastCandleSticks);
                $sharesAmount = $this->getSharesAmount($tradingCapital, $stopLoss, $enterPrice);

                $tradingCapitalBeforeTrade = $tradingCapital;
                $tradingCapitalAfterTrade = $this->getProfit($security, $stopLoss, $sharesAmount, $tradingDate, $enterPrice, $spread);
                $tradingCapital = $tradingCapital - $tradeCapital + $tradingCapitalAfterTrade;


                $this->addTradingDataInformation(BaseConstants::TRADE_ENTER_PRICE, $enterPrice);
                $this->addTradingDataInformation(BaseConstants::TRADING_CAPITAL, $tradingCapital);
                $this->addTradingDataInformation(BaseConstants::TRADE_SPREAD, $spread);
                $taxFee = $this->technicalIndicatorsService->calculateTaxFee($sharesAmount, $tradeCapital);
                $this->addTradingDataInformation(BaseConstants::TRADE_FEE, $taxFee);

                $tradingCapital -= $taxFee;

                if($tradingCapitalBeforeTrade < $tradingCapital)
                    $this->addTradingDataInformation(BaseConstants::IS_WINNER, true);
                else 
                    $this->addTradingDataInformation(BaseConstants::IS_WINNER, false);

                $this->results[BaseConstants::TRADES_INFORMATION][] = $this->trade_information;
                $this->results[BaseConstants::AMOUNT_OF_TRADES]++;

                if(++$tradesCounter >= self::MAX_AMOUNT_TRADES_PER_DAY)
                    return $tradingCapital;
            }
        }

        return $tradingCapital;
    }

    private function getProfit($security, $stopLoss, $sharesAmount, DateTime $tradingDate, float $enterPrice, float $spread) : float 
    {
        $this->addTradingDataInformation(BaseConstants::TRADE_DATE, $tradingDate->format('Y-m-d'));
        $this->addTradingDataInformation(BaseConstants::TRADE_SECURITY_TICKER, $security->getTicker());
        $this->addTradingDataInformation(BaseConstants::TRADE_STOP_LOSS_PRICE, $stopLoss);
        $this->addTradingDataInformation(BaseConstants::TRADE_TAKE_PROFIT_PRICE, 0);
        $this->addTradingDataInformation(BaseConstants::TRADE_RISK_REWARD, null);
        $this->addTradingDataInformation(BaseConstants::TRADE_POSITION, 'Long');


        $nextCandleSticks = $security->getNextNCandleSticks($tradingDate, self::AMOUNT_OF_NEXT_CANDLESTICKS);
        $previousCandleStick = null;
        $firstTargetReach = false;
        $initialPrice = $enterPrice;
        foreach ($nextCandleSticks as $candleStick) {
            if($candleStick->getDate() == $tradingDate)
            {
                $previousCandleStick = $candleStick;
                continue;
            }

            /** @var CandleStick $candleStick */
            $closePrice = $candleStick->getClosePrice();
            $exitDate = $candleStick->getDate();


            $nasdaqIndex = $this->entityManager->getRepository(Security::class)->findOneBy(['ticker' => BaseConstants::NASDAQ_2000_TICKER]);
            $lastNasdaqMarketCandleSticks = $nasdaqIndex->getLastNCandleSticks($exitDate, self::AMOUNT_OF_PREVIOUS_CANDLESTICKS);
            $lastNasdaqCandleStick = $this->getLastCandleStick($lastNasdaqMarketCandleSticks);
    
            $nasdaqMarketPrices = $this->extractClosingPricesFromCandlesticks($lastNasdaqMarketCandleSticks);
            $sma200Nasdaq = $this->technicalIndicatorsService->calculateSMA($nasdaqMarketPrices, 200);

            if($sma200Nasdaq > $lastNasdaqCandleStick->getClosePrice())  // That's probably a looser.
            {
                // so that means that you should leave your position 30 minutes before market close let's say
                $this->addTradingDataInformation(BaseConstants::TRADE_EXIT_PRICE, $closePrice - $spread);
                $this->addTradingDataInformation(BaseConstants::EXIT_DATE, $exitDate->format('Y-m-d'));
                $this->addTradingDataInformation(BaseConstants::TRADE_RISK_REWARD, null);

                return ($stopLoss - $spread) * $sharesAmount;
            }

            // After every simulation take screenshot and update readme.md file.
            if($closePrice <= $stopLoss && $firstTargetReach)       // we can consider this as a winner but it might a be a looser
            {
                // so that means that you should leave your position 30 minutes before market close let's say
                $this->addTradingDataInformation(BaseConstants::TRADE_EXIT_PRICE, $stopLoss - $spread);
                $this->addTradingDataInformation(BaseConstants::EXIT_DATE, $exitDate->format('Y-m-d'));

                if($stopLoss > $initialPrice)
                {
                    $riskReward = ($stopLoss  - $initialPrice) / ($initialPrice - $this->trade_information[BaseConstants::TRADE_STOP_LOSS_PRICE]);
                    $this->addTradingDataInformation(BaseConstants::TRADE_RISK_REWARD, $riskReward);
                }
                else 
                {
                    $this->addTradingDataInformation(BaseConstants::TRADE_RISK_REWARD, null);
                }
   

                return ($stopLoss - $spread) * $sharesAmount;
            }

            if($closePrice <= $stopLoss)        // That's a looser.
            {
                // so that means that you should leave your position 30 minutes before market close let's say
                $this->addTradingDataInformation(BaseConstants::TRADE_EXIT_PRICE, $stopLoss - $spread);
                $this->addTradingDataInformation(BaseConstants::EXIT_DATE, $exitDate->format('Y-m-d'));
                $this->addTradingDataInformation(BaseConstants::TRADE_RISK_REWARD, null);

                return ($stopLoss - $spread) * $sharesAmount;
            }

            if($closePrice < $previousCandleStick->getClosePrice() && $closePrice > $enterPrice)    // we updating stop loss
            {
                $firstTargetReach = true;
                $enterPrice = $closePrice;
                $last5CandleSticks = $security->getLastNCandleSticks($candleStick->getDate(), 5);
                $atr5 = $this->technicalIndicatorsService->calculateATR($last5CandleSticks, 5);
                $stopLoss = $closePrice - self::AMOUNT_OF_ATR5_FOR_STOP_LOSS * $atr5;     
            }

            $previousCandleStick = $candleStick;
        }

        $exitDate = $candleStick->getDate()->format('Y-m-d');
        $closePrice = $candleStick->getClosePrice();
        $this->addTradingDataInformation(BaseConstants::EXIT_DATE, $exitDate);
        $this->addTradingDataInformation(BaseConstants::TRADE_EXIT_PRICE, $closePrice);

        return $candleStick->getClosePrice() * $sharesAmount - self::TRADE_FEE;
    }

    private function isSecurityEligibleForTrading(array $lastCandleSticks, $tradingDate, $security) : bool
    {
        if(count($lastCandleSticks) < self::MIN_AMOUNT_OF_CANDLESTICKS)    
            return false;

        $lastCandleStick = $lastCandleSticks[count($lastCandleSticks) - 1];
        $volume = $lastCandleStick->getVolume();

        if($lastCandleStick->getClosePrice() < self::MIN_PRICE || $volume < self::MIN_VOLUME)
            return false;

        $prices = $this->extractClosingPricesFromCandlesticks($lastCandleSticks);
        $sma200 = $this->technicalIndicatorsService->calculateSMA($prices, 200);
        $sma20 = $this->technicalIndicatorsService->calculateSMA($prices, 20);


        if($this->isCandlestickStrongHammerPattern($lastCandleStick, $lastCandleSticks) 
            && $lastCandleStick->getClosePrice() > $sma200
            && $lastCandleStick->getClosePrice() < $sma20
          )
          {
            //TODO: next calculate stop loss. Stop loss is below sma200 - atr5.
            $last5CandleSticks = $security->getLastNCandleSticks($tradingDate, 5);
            $atr5 = $this->technicalIndicatorsService->calculateATR($last5CandleSticks, 5);

            $stopLoss = $sma200 - $atr5;

            $this->addTradingDataInformation(BaseConstants::TRADE_STOP_LOSS_PRICE, $stopLoss);
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
            $closePrice > $openPrice                 // Close should be above or close to the open price
        ) {
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

    private function extractClosingPricesFromCandlesticks(array $candleSticks) : array
    {
        $prices = [];
        foreach ($candleSticks as $candleStick) {
            $prices[] = (float) $candleStick->getClosePrice();
        }

        return $prices;
    }

    private function getLastCandleStick(array $lastCandleSticks)
    {
        return $lastCandleSticks[count($lastCandleSticks) - 1];
    }


    public function canITrade(Security $security, DateTime $tradingDate) : bool
    {
        $lastCandleSticks = $security->getLastNCandleSticks($tradingDate, self::AMOUNT_OF_PREVIOUS_CANDLESTICKS);
        if($this->isSecurityEligibleForTrading($lastCandleSticks, $tradingDate, $security))
        {
            return true;
        }

        return false;
    }

    public function shouldIExit(Security $security, $stopLoss, $sharesAmount, DateTime $tradingDate, float $enterPrice, array $nextCandleSticks) : bool 
    {
        return false;
    }
}