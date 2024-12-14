<?php

namespace App\Strategy;

use App\Entity\Security;
use App\Entity\CandleStick;
use App\Interface\SwingTradingStrategyInterface;
use App\Constants\BaseConstants;
use App\Service\TechnicalIndicatorsService;
use App\Interface\MarketIndexInterface;

use DateTime;
/** Strategy at first glance looks profitable and quite a good one, still too early to judge
 * tends to win about 75 percent of trades with 0.39 risk reward according 2024 montecarlo simulation
 * how does it work: you enter the trade when close is above sma200 and the close below sma10
 * exit the trade when the price drops 10 percent from the entry price 
 * or previous candle close is higher than current candle close.
 * as you see it look for pretty liquid stocks 2_000_000. It might be challenging to find this on 2016 
 * 
 * i removed exit sma10 condition because it gave me better results.
 */
class SimplePullbackStrategy implements SwingTradingStrategyInterface
{
    private const MIN_AMOUNT_OF_MONEY = 20;

    private const AMOUNT_OF_PREVIOUS_CANDLESTICKS = 450;
    private const AMOUNT_OF_NEXT_CANDLESTICKS = 100;
    private const MIN_VOLUME = 2_000_000;
    private const CAPITAL_RISK = 0.1;
    private const MAX_AMOUNT_TRADES_PER_DAY = 1;
    private const MIN_AMOUNT_OF_CANDLESTICKS = 200;

    private const PYRAMIDING_TRADES_AMOUNT = 3;

    private const MIN_PRICE = 1;

    private array $trade_information = [];
    private array $results = [];
    private float $maxDrawdown;
    private float $highestCapitalValue;
    private array $lastTradesInformation = [];

    private MarketIndexInterface $marketIndex;
    private TechnicalIndicatorsService $technicalIndicatorsService;

    public function __construct(MarketIndexInterface $marketIndex,
                                TechnicalIndicatorsService $technicalIndicatorsService) {
        $this->marketIndex = $marketIndex;
        $this->technicalIndicatorsService = $technicalIndicatorsService;
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

            
            $tradingCapital = $this->processPyramidingTrades($startDate, $tradingCapital);

            if(count($this->lastTradesInformation) == self::PYRAMIDING_TRADES_AMOUNT)
            {
                $startDate->modify('+1 day');
                continue;
            }


            $tradingCapital = $this->getTradingCapitalAfterDay($startDate, $securities, $tradingCapital);

            $randomDateInterval = (int)mt_rand(2, 4);
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

        $this->processPyramidingTrades($startDate, $tradingCapital, true);
        $this->sortTradesInfomationExitDates();
        $this->results[BaseConstants::FINAL_TRADING_CAPITAL] = $tradingCapital;
        return $this->results;
    }

    private function getTradingCapitalAfterDay(DateTime $tradingDate, array $securities, float $tradingCapital)
    {
        shuffle($securities);
        $tradesCounter = 0;
        $specificDayTradingCapital = $tradingCapital;
        foreach ($securities as $security) {
            // skips nasdaq2000 overall market security
            if($security->getTicker() == BaseConstants::NASDAQ_2000_TICKER 
                || !$this->isTradeable($security->getTicker())
                || $security->getIsForex()
                || $security->getIsCrypto()
              )
                continue;

            $lastCandleSticks = $security->getLastNCandleSticks($tradingDate, self::AMOUNT_OF_PREVIOUS_CANDLESTICKS);

            // echo "Date: " . $tradingDate->format('Y-m-d') . $security->getTicker() . "\n\r";
            
            if($this->isSecurityEligibleForTrading($lastCandleSticks))
            {
                $lastCandleStick = $this->getLastCandleStick($lastCandleSticks);
                $spread = $this->technicalIndicatorsService->calculateSpread($lastCandleSticks);
                $enterPrice = $lastCandleStick->getClosePrice();      // I'm do this because 
                $stopLoss = 0.9 * $enterPrice; 
                $sharesAmount = $this->getSharesAmount($tradingCapital, $stopLoss, $enterPrice);
                $enterPrice += $spread;
                $tradeCapital = $this->getTradeCapital($tradingCapital, $enterPrice, $sharesAmount);
                // Checks whether do we have enough money to afford this trade
                if(!$tradeCapital)
                    continue;


                $tradingCapitalAfterTrade = $this->getProfit($security, 
                                                            $stopLoss, 
                                                            $sharesAmount, 
                                                            $tradingDate, 
                                                            $enterPrice);

                $tradingCapitalBeforeTrade = $tradingCapital;
                $tradingCapital = $tradingCapital - $tradeCapital + $tradingCapitalAfterTrade;

                $this->addTradingDataInformation(BaseConstants::TRADE_ENTER_PRICE, $enterPrice);
                $this->results[BaseConstants::AMOUNT_OF_TRADES]++;

                $taxFee = $this->technicalIndicatorsService->calculateTaxFee($sharesAmount, $tradeCapital);
                $this->addTradingDataInformation(BaseConstants::TRADE_FEE, $taxFee);
                $this->addTradingDataInformation(BaseConstants::TRADE_SPREAD, $spread);

                $tradingCapital -= $taxFee;

                $tradeIncome = $tradingCapital - $tradingCapitalBeforeTrade;
                $this->addTradingDataInformation(BaseConstants::TRADING_CAPITAL, $tradeIncome);
                
                if($tradingCapitalBeforeTrade < $tradingCapital)
                    $this->addTradingDataInformation(BaseConstants::IS_WINNER, true);
                else 
                    $this->addTradingDataInformation(BaseConstants::IS_WINNER, false);


                $this->results[BaseConstants::TRADES_INFORMATION][] = $this->trade_information;
                $this->lastTradesInformation[] = $this->trade_information;

                $tradingCapital = $tradingCapitalBeforeTrade;

                if(++$tradesCounter >= self::MAX_AMOUNT_TRADES_PER_DAY || $tradingCapital < self::MIN_AMOUNT_OF_MONEY)
                    return $tradingCapital;
            }
        }

        return $tradingCapital;
    }
    
    private function isSecurityEligibleForTrading(array $lastCandleSticks) : bool
    {
        if(count($lastCandleSticks) < self::MIN_AMOUNT_OF_CANDLESTICKS)
            return false;

        $lastCandleStick = $this->getLastCandleStick($lastCandleSticks);
        $closePrice = $lastCandleStick->getClosePrice();
        $prices = $this->extractClosingPricesFromCandlesticks($lastCandleSticks);
        $sma200 = $this->technicalIndicatorsService->calculateSMA($prices, 200);
        $sma10 = $this->technicalIndicatorsService->calculateSMA($prices, 10);

        $volume = $lastCandleStick->getVolume();
        
        if($closePrice > $sma200 && $closePrice < $sma10 && $volume > self::MIN_VOLUME && $closePrice > self::MIN_PRICE)
            return true;

        return false;
    }

    private function getLastCandleStick(array $lastCandleSticks)
    {
        return $lastCandleSticks[count($lastCandleSticks) - 1];
    }

    private function getProfit(Security $security, $stopLoss, $sharesAmount, DateTime $tradingDate, float $enterPrice) : float 
    {
        $this->addTradingDataInformation(BaseConstants::TRADE_DATE, $tradingDate->format('Y-m-d'));
        $this->addTradingDataInformation(BaseConstants::TRADE_SECURITY_TICKER, $security->getTicker());
        $this->addTradingDataInformation(BaseConstants::TRADE_STOP_LOSS_PRICE, $stopLoss);
        $this->addTradingDataInformation(BaseConstants::TRADE_TAKE_PROFIT_PRICE, 0);
        $this->addTradingDataInformation(BaseConstants::TRADE_POSITION, 'Long');


        $nextCandleSticks = $security->getNextNCandleSticks($tradingDate, self::AMOUNT_OF_NEXT_CANDLESTICKS);
        $previousCandleStick = null;
        foreach ($nextCandleSticks as $candleStick) {
            if($candleStick->getDate() == $tradingDate)
            {
                $previousCandleStick = $candleStick;
                continue;
            }

            /** @var CandleStick $candleStick */
            $closePrice = $candleStick->getClosePrice();
            $lowestPrice = $candleStick->getLowestPrice();
            $highestPrice = $candleStick->getHighestPrice();
            $exitDate = $candleStick->getDate();

            $last10CandleSticks = $security->getLastNCandleSticks($exitDate, 10);
            $spread = $this->technicalIndicatorsService->calculateSpread($last10CandleSticks);

            if($lowestPrice <= $stopLoss)
            {
                $this->addTradingDataInformation(BaseConstants::TRADE_EXIT_PRICE, $stopLoss - $spread);
                $this->addTradingDataInformation(BaseConstants::EXIT_DATE, $exitDate->format('Y-m-d'));
                $this->addTradingDataInformation(BaseConstants::TRADE_RISK_REWARD, null);

                return ($stopLoss - $spread) * $sharesAmount;
            }

            if($closePrice < $previousCandleStick->getClosePrice() && $closePrice > $enterPrice)
            {
                // so that means that you should leave your position 30 minutes before market close let's say
                $this->addTradingDataInformation(BaseConstants::TRADE_EXIT_PRICE, $closePrice - $spread);
                $this->addTradingDataInformation(BaseConstants::EXIT_DATE, $exitDate->format('Y-m-d'));

                $riskRewardRatio = ($closePrice - $enterPrice - $spread) / ($enterPrice - $stopLoss) ;
                $this->addTradingDataInformation(BaseConstants::TRADE_RISK_REWARD, $riskRewardRatio);



                return ($closePrice - $spread) * $sharesAmount;
            }

            $previousCandleStick = $candleStick;
        }

        $exitDate = $candleStick->getDate()->format('Y-m-d');
        $closePrice = $candleStick->getClosePrice();
        $this->addTradingDataInformation(BaseConstants::EXIT_DATE, $exitDate);
        $this->addTradingDataInformation(BaseConstants::TRADE_EXIT_PRICE, $closePrice);
        $this->addTradingDataInformation(BaseConstants::TRADE_RISK_REWARD, null);

        return $candleStick->getClosePrice() * $sharesAmount;
    }

    private function getTradeCapital($tradingCapital, $enterPrice, $sharesAmount)
    {
        if($enterPrice * $sharesAmount > $tradingCapital * 3)   // i will replace this at later point.
            return false;

        return $sharesAmount * $enterPrice;
    }

    private function getSharesAmount($tradingCapital, $stopLoss, $enterPrice) : int
    {
        $riskCapital = $tradingCapital * self::CAPITAL_RISK;
        $sharesAmount = (int)($riskCapital / (abs($enterPrice - $stopLoss)));

        return $sharesAmount;
    }

    private function addTradingDataInformation($key, $value)
    {
        $this->trade_information[$key] = $value;
    }

    private function isTradeable($ticker) : bool
    {
        foreach ($this->lastTradesInformation as $key => $tradeInformation) {
            if ($tradeInformation[BaseConstants::TRADE_SECURITY_TICKER] == $ticker) {
                return false;
            }
        }
        return true;
    }

    private function extractClosingPricesFromCandlesticks(array $candleSticks) : array
    {
        $prices = [];
        foreach ($candleSticks as $candleStick) {
            $prices[] = (float) $candleStick->getClosePrice();
        }

        return $prices;
    }

    private function sortTradesInfomationExitDates()
    {
        $tradesInformation = $this->results[BaseConstants::TRADES_INFORMATION];
        usort($tradesInformation, function($a, $b) {
            return strtotime($a[BaseConstants::EXIT_DATE]) <=> strtotime($b[BaseConstants::EXIT_DATE]); // Ascending order
        });

        $this->results[BaseConstants::TRADES_INFORMATION] = $tradesInformation;
    }


    private function updateResultTradingInformation($tradingCapital, $tradeInformation)
    {
        for ($i=0; $i < count($this->results[BaseConstants::TRADES_INFORMATION]); $i++) { 
            if($this->results[BaseConstants::TRADES_INFORMATION][$i] == $tradeInformation)
            {
                $this->results[BaseConstants::TRADES_INFORMATION][$i][BaseConstants::TRADING_CAPITAL] = $tradingCapital;
                break;
            }
        }
    }
    
    private function processPyramidingTrades(DateTime $date, $tradingCapital, $finishAll = false)
    {
        // first you need to sort them by exit date ascennding
        usort($this->lastTradesInformation, function($a, $b) {
            return strtotime($a[BaseConstants::EXIT_DATE]) <=> strtotime($b[BaseConstants::EXIT_DATE]); // Ascending order
        });

        foreach ($this->lastTradesInformation as $key => $tradeInformation) {
            if (strtotime($tradeInformation[BaseConstants::EXIT_DATE]) <= strtotime($date->format('Y-m-d')) || $finishAll) {
                $tradingCapital += $tradeInformation[BaseConstants::TRADING_CAPITAL];
                $this->updateResultTradingInformation($tradingCapital, $tradeInformation);
                unset($this->lastTradesInformation[$key]); // Remove element
            }
        }

        return $tradingCapital;
    }

    public function canITrade(Security $security, DateTime $tradingDate) : bool
    {
        $lastCandleSticks = $security->getLastNCandleSticks($tradingDate, self::AMOUNT_OF_PREVIOUS_CANDLESTICKS);
        // echo "Date: " . $tradingDate->format('Y-m-d') . $security->getTicker() . "\n\r";
        if($this->isSecurityEligibleForTrading($lastCandleSticks))
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