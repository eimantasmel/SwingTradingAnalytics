<?php

namespace App\Strategy;

use App\Entity\Security;
use App\Entity\CandleStick;
use App\Interface\SwingTradingStrategyInterface;
use App\Constants\BaseConstants;
use App\Service\TechnicalIndicatorsService;
use App\Interface\MarketIndexInterface;

use DateTime;

class MeanReversionStrategy implements SwingTradingStrategyInterface
{
    private const MIN_AMOUNT_OF_MONEY = 20;

    private const AMOUNT_OF_PREVIOUS_CANDLESTICKS = 450;
    private const AMOUNT_OF_NEXT_CANDLESTICKS = 100;
    private const MIN_VOLUME = 2_000_000;
    private const CAPITAL_RISK = 0.1;
    private const MAX_AMOUNT_TRADES_PER_DAY = 3;

    private const MIN_GAP = 0.01;
    private const STOP_LOSS_PERCENTAGE = 0.1;
    private const MIN_PRICE = 0.1;

    private array $trade_information = [];
    private array $results = [];
    private float $maxDrawdown;
    private float $highestCapitalValue;
    private const RSI_OVERSOLD = 25;
    private const RSI_OVERBOUGHT = 75;

    private const PYRAMIDING_TRADES_AMOUNT = 3;


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
            $tradingCapital = $this->getTradingCapitalAfterDay($startDate, $securities, $tradingCapital);

            $randomDateInterval = (int)mt_rand(2, 8);
            $startDate->modify("+{$randomDateInterval} days");
            // $startDate->modify('+7 day');

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
            // skips nasdaq2000 overall market security
            if($security->getTicker() == BaseConstants::NASDAQ_2000_TICKER)
                continue;

            // $lastCandleSticks = $this->getLastNCandleSticks($security, $tradingDate);
            $lastCandleSticks = $security->getLastNCandleSticks($tradingDate, self::AMOUNT_OF_PREVIOUS_CANDLESTICKS);
            // echo "Date: " . $tradingDate->format('Y-m-d') . $security->getTicker() . "\n\r";
            if($this->isSecurityEligibleForTrading($lastCandleSticks))
            {
                $lastCandleStick = $this->getLastCandleStick($lastCandleSticks);
                $enterPrice = $lastCandleStick->getClosePrice();      // I'm do this because 
                $stopLoss = $this->trade_information[BaseConstants::TRADE_STOP_LOSS_PRICE]; 
                $tradeCapital = $this->getTradeCapital($tradingCapital, $stopLoss, $enterPrice);
                // Checks whether do we have enough money to afford this trade
                if(!$tradeCapital)
                    continue;

                $sharesAmount = $this->getSharesAmount($tradingCapital, $stopLoss, $enterPrice);

                $spread = $this->technicalIndicatorsService->calculateSpread($lastCandleSticks);

                $tradingCapitalAfterTrade = $this->getProfit($security, 
                                                            $stopLoss, 
                                                            $sharesAmount, 
                                                            $tradingDate, 
                                                            $spread, 
                                                            $enterPrice);

                $tradingCapitalBeforeTrade = $tradingCapital;

                if($this->trade_information[BaseConstants::TRADE_POSITION] == 'Long')
                    $tradingCapital = $tradingCapital - $tradeCapital + $tradingCapitalAfterTrade;
                else
                    $tradingCapital = $tradingCapital - $tradingCapitalAfterTrade + $tradeCapital ;

                $this->addTradingDataInformation(BaseConstants::TRADE_ENTER_PRICE, $enterPrice);
                $this->results[BaseConstants::AMOUNT_OF_TRADES]++;

                $taxFee = $this->technicalIndicatorsService->calculateTaxFee($sharesAmount, $tradeCapital);
                $this->addTradingDataInformation(BaseConstants::TRADE_FEE, $taxFee);
                $this->addTradingDataInformation(BaseConstants::TRADE_SPREAD, $spread);

                $tradingCapital -= $taxFee;

                if($tradingCapitalBeforeTrade >= $tradingCapital)
                    $this->addTradingDataInformation(BaseConstants::IS_WINNER, false);
                else
                    $this->addTradingDataInformation(BaseConstants::IS_WINNER, true);

                $this->addTradingDataInformation(BaseConstants::TRADING_CAPITAL, $tradingCapital);
                $this->results[BaseConstants::TRADES_INFORMATION][] = $this->trade_information;

                if(++$tradesCounter >= self::MAX_AMOUNT_TRADES_PER_DAY || $tradingCapital < self::MIN_AMOUNT_OF_MONEY)
                    return $tradingCapital;
            }
        }

        return $tradingCapital;
    }
    
    private function isSecurityEligibleForTrading(array $lastCandleSticks) : bool
    {
        $lastCandleStick = $this->getLastCandleStick($lastCandleSticks);
        $price = (float)$lastCandleStick->getClosePrice();
        $volume = (float)$lastCandleStick->getVolume();

        if($price < self::MIN_PRICE || $volume < self::MIN_VOLUME)
            return false;

        $rsi = $this->technicalIndicatorsService->calculateRSI($lastCandleSticks);
        $prices = $this->extractClosingPricesFromCandlesticks($lastCandleSticks);
        $sma200 = $this->technicalIndicatorsService->calculateSMA($prices, 200);

        if($rsi > self::RSI_OVERBOUGHT && $price < $sma200)
        {
            $this->addTradingDataInformation(BaseConstants::TRADE_POSITION, "Short");
            $this->addTradingDataInformation(BaseConstants::TRADE_STOP_LOSS_PRICE, $price * (1 + self::STOP_LOSS_PERCENTAGE));
            return true;
        }

        if($rsi < self::RSI_OVERSOLD && $price > $sma200)
        {
            $this->addTradingDataInformation(BaseConstants::TRADE_POSITION, "Long");
            $this->addTradingDataInformation(BaseConstants::TRADE_STOP_LOSS_PRICE, $price * (1 - self::STOP_LOSS_PERCENTAGE));
            return true;
        }

        return false;
    }

    private function getLastCandleStick(array $lastCandleSticks)
    {
        return $lastCandleSticks[count($lastCandleSticks) - 1];
    }

    private function getProfit(Security $security, $stopLoss, $sharesAmount, DateTime $tradingDate, float $spread, float $enterPrice) : float 
    {
        $this->addTradingDataInformation(BaseConstants::TRADE_DATE, $tradingDate->format('Y-m-d'));
        $this->addTradingDataInformation(BaseConstants::TRADE_SECURITY_TICKER, $security->getTicker());
        $this->addTradingDataInformation(BaseConstants::TRADE_TAKE_PROFIT_PRICE, 0);
        

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
            $highestPrice = $candleStick->getHighestPrice();
            $lowestPrice = $candleStick->getLowestPrice();
            $exitDate = $candleStick->getDate();

            $spread = $this->trade_information[BaseConstants::TRADE_POSITION] == "Long" ? $spread : -1 * $spread;
            $position = $this->trade_information[BaseConstants::TRADE_POSITION];

            $last5CandleSticks = $security->getLastNCandleSticks($exitDate, 5);
            $prices = $this->extractClosingPricesFromCandlesticks($last5CandleSticks);
            $sma5 = $this->technicalIndicatorsService->calculateSMA($prices, 5);

            if($lowestPrice < $stopLoss && $stopLoss < $highestPrice)
            {
                $this->addTradingDataInformation(BaseConstants::TRADE_EXIT_PRICE, $stopLoss - $spread);
                $this->addTradingDataInformation(BaseConstants::EXIT_DATE, $exitDate->format('Y-m-d'));

                return ($stopLoss - $spread) * $sharesAmount;
            }

            if($position == 'Long' && $closePrice > $sma5 && $closePrice > $enterPrice && $closePrice < $previousCandleStick->getClosePrice()) 
            {
                $this->addTradingDataInformation(BaseConstants::TRADE_EXIT_PRICE, $closePrice - $spread);
                $this->addTradingDataInformation(BaseConstants::EXIT_DATE, $exitDate->format('Y-m-d'));


                $riskReward = ($closePrice  - $enterPrice) / ($enterPrice - $this->trade_information[BaseConstants::TRADE_STOP_LOSS_PRICE]);
                $this->addTradingDataInformation(BaseConstants::TRADE_RISK_REWARD, $riskReward);

                return ($closePrice - $spread) * $sharesAmount;
            }

            // && $closePrice > $previousCandleStick->getClosePrice()
            if($position == 'Short' && $closePrice < $sma5 && $closePrice < $enterPrice && $closePrice > $previousCandleStick->getClosePrice()) 
            {
                $this->addTradingDataInformation(BaseConstants::TRADE_EXIT_PRICE, $closePrice - $spread);
                $this->addTradingDataInformation(BaseConstants::EXIT_DATE, $exitDate->format('Y-m-d'));

                $riskReward = ($closePrice  - $enterPrice) / ($enterPrice - $this->trade_information[BaseConstants::TRADE_STOP_LOSS_PRICE]);
                $this->addTradingDataInformation(BaseConstants::TRADE_RISK_REWARD, $riskReward);

                return ($closePrice - $spread) * $sharesAmount;
            }


            $previousCandleStick = $candleStick;
        }

        $exitDate = $candleStick->getDate()->format('Y-m-d');
        $closePrice = $candleStick->getClosePrice();
        $this->addTradingDataInformation(BaseConstants::EXIT_DATE, $exitDate);
        $this->addTradingDataInformation(BaseConstants::TRADE_EXIT_PRICE, $closePrice);

        return $candleStick->getClosePrice() * $sharesAmount;
    }

    private function getTradeCapital($tradingCapital, $stopLoss, $enterPrice)
    {
        $sharesAmount = $this->getSharesAmount($tradingCapital, $stopLoss, $enterPrice);
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

    private function extractClosingPricesFromCandlesticks(array $candleSticks) : array
    {
        $prices = [];
        foreach ($candleSticks as $candleStick) {
            $prices[] = (float) $candleStick->getClosePrice();
        }

        return $prices;
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