<?php

namespace App\Service;

use App\Entity\Security;
use App\Entity\CandleStick;
use App\Interface\SwingTradingStrategyInterface;
use App\Constants\BaseConstants;
use App\Service\Nasdaq2000IndexService;
use App\Service\TechnicalIndicatorsService;

use DateTime;

class ChatGptStrategy implements SwingTradingStrategyInterface
{
    private const MIN_AMOUNT_OF_MONEY = 20;

    private const AMOUNT_OF_PREVIOUS_CANDLESTICKS = 210;
    private const AMOUNT_OF_NEXT_CANDLESTICKS = 100;
    private const MIN_VOLUME = 300_000;
    private const CAPITAL_RISK = 0.085;
    private const RISK_REWARD_RATIO = 2;
    private const TRADE_FEE = 1;
    private const MAX_AMOUNT_TRADES_PER_DAY = 5;

    private const MIN_PRICE = 0.1;
    private const MAX_PRICE = 700;

    private array $trade_information = [];
    private array $results = [];

    private Nasdaq2000IndexService $nasdaq2000IndexService;
    private TechnicalIndicatorsService $technicalIndicatorsService;

    public function __construct(Nasdaq2000IndexService $nasdaq2000IndexService,
                                TechnicalIndicatorsService $technicalIndicatorsService) {
        $this->nasdaq2000IndexService = $nasdaq2000IndexService;
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
            $startDate->modify('+14 day');

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
            echo "Date: " . $tradingDate->format('Y-m-d') . $security->getTicker() . "\n\r";
            if($this->isSecurityEligibleForTrading($lastCandleSticks))
            {
                $trade = $this->calculateTradeValues($lastCandleSticks);

                $this->addTradingDataInformation(BaseConstants::TRADE_POSITION, $trade['position']);

                $enterPrice = $trade['entryPrice'];       // I'm do this because 
                $stopLoss = $trade['stopLoss']; 
                $takeProfit = $trade['takeProfit']; 
                $tradeCapital = $this->getTradeCapital($tradingCapital, $stopLoss, $enterPrice);
                // Checks whether do we have enough money to afford this trade
                if(!$tradeCapital)
                    continue;

                $sharesAmount = $this->getSharesAmount($tradingCapital, $stopLoss, $enterPrice);

                $spread = $this->technicalIndicatorsService->calculateSpread($lastCandleSticks);

                $tradingCapitalAfterTrade = $this->getProfit($security, 
                                                            $stopLoss, 
                                                            $takeProfit, 
                                                            $sharesAmount, 
                                                            $tradingDate, 
                                                            $spread, 
                                                            $trade['position'], 
                                                            $tradeCapital);

                if($trade['position'] == "Long")
                    $tradingCapital = $tradingCapital - $tradeCapital + $tradingCapitalAfterTrade;
                else
                    $tradingCapital = $tradingCapital - $tradingCapitalAfterTrade + $tradeCapital;


                if($tradingCapitalAfterTrade > $tradeCapital && $trade['position'] == 'Long')
                    $this->addTradingDataInformation(BaseConstants::IS_WINNER, true);
                else if ($tradingCapitalAfterTrade < $tradeCapital && $trade['position'] == 'Short')   
                    $this->addTradingDataInformation(BaseConstants::IS_WINNER, true);
                else if($tradingCapitalAfterTrade < $tradeCapital && $trade['position'] == 'Long')
                    $this->addTradingDataInformation(BaseConstants::IS_WINNER, false);
                else 
                    $this->addTradingDataInformation(BaseConstants::IS_WINNER, false);

                $this->addTradingDataInformation(BaseConstants::TRADE_ENTER_PRICE, $enterPrice);
                $this->results[BaseConstants::AMOUNT_OF_TRADES]++;

                $taxFee = $this->technicalIndicatorsService->calculateTaxFee($sharesAmount);
                $tradingCapital -= $taxFee;

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
        $lastCandleStick = $lastCandleSticks[count($lastCandleSticks) - 1];
        $endDate = $lastCandleStick->getDate();
        $startDate = (clone $endDate)->modify('-1 month');
        $cagrOfMarketOFLastMonth = $this->nasdaq2000IndexService->getCagrOfDates($startDate, $endDate);


        $trade = $this->calculateTradeValues($lastCandleSticks);
        if($trade 
                && $trade['canItradeOnNextCandleStick'] 
                && $cagrOfMarketOFLastMonth 
                && $cagrOfMarketOFLastMonth > 0
                && $trade['position'] == 'Long'
          )
            return true;

        if($trade 
            && $trade['canItradeOnNextCandleStick'] 
            && $cagrOfMarketOFLastMonth 
            && $cagrOfMarketOFLastMonth < 0
            && $trade['position'] == 'Short'
        ) 
            return true;

        return false;
    }

    private function getProfit($security, $stopLoss, $takeProfit, $sharesAmount, DateTime $tradingDate, float $spread, string $position, float $tradeCapital) : float 
    {
        $this->addTradingDataInformation(BaseConstants::TRADE_DATE, $tradingDate->format('Y-m-d'));
        $this->addTradingDataInformation(BaseConstants::TRADE_SECURITY_TICKER, $security->getTicker());
        $this->addTradingDataInformation(BaseConstants::TRADE_STOP_LOSS_PRICE, $stopLoss);
        $this->addTradingDataInformation(BaseConstants::TRADE_TAKE_PROFIT_PRICE, $takeProfit);

        $nextCandleSticks = $security->getNextNCandleSticks($tradingDate, self::AMOUNT_OF_NEXT_CANDLESTICKS);
        $spread = $position == 'Long' ? $spread : -1 * $spread;
        $spread = 0;
        foreach ($nextCandleSticks as $candleStick) {
            if($candleStick->getDate() == $tradingDate)
                continue;

            /** @var CandleStick $candleStick */
            $closePrice = $candleStick->getClosePrice();
            $lowestPrice = $candleStick->getLowestPrice();
            $highestPrice = $candleStick->getHighestPrice();
            $exitDate = $candleStick->getDate()->format('Y-m-d');

            // this trade is losser
            if($lowestPrice <= $stopLoss && $stopLoss <= $highestPrice)
            {
                $this->addTradingDataInformation(BaseConstants::TRADE_EXIT_PRICE, $stopLoss);
                $this->addTradingDataInformation(BaseConstants::EXIT_DATE, $exitDate);
                return ($stopLoss - $spread) * $sharesAmount;
            }

            // this trade is a winner
            if($highestPrice >= $takeProfit && $takeProfit >= $lowestPrice )
            {
                $this->addTradingDataInformation(BaseConstants::TRADE_EXIT_PRICE, $takeProfit);
                $this->addTradingDataInformation(BaseConstants::EXIT_DATE, $exitDate);
                return ($takeProfit + $spread) * $sharesAmount;
            }
        }

        $exitDate = $candleStick->getDate()->format('Y-m-d');
        $closePrice = $candleStick->getClosePrice();
        $this->addTradingDataInformation(BaseConstants::EXIT_DATE, $exitDate);
        $this->addTradingDataInformation(BaseConstants::TRADE_EXIT_PRICE, $closePrice);

        return $candleStick->getClosePrice() * $sharesAmount;
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

   
    //-------------------------------------------------------------------------------------------------------------------
    /** TODO: i will uncomment this later to check how this method works */
    private function calculateTradeValues($candlesticks)
    {
        $n = count($candlesticks);
    
        if ($n < 50) {
            // Not enough data for EMA(50), EMA(200), and MACD calculation
            return null;
        }
    
        // Extract data from candlesticks
        $closePrices = [];
        $highPrices = [];
        $lowPrices = [];
        foreach ($candlesticks as $candle) {
            $closePrices[] = $candle->getClosePrice();
            $highPrices[] = $candle->getHighestPrice();
            $lowPrices[] = $candle->getLowestPrice();
        }
        
        // Calculate EMA (50) and EMA (200)
        $ema50 = $this->technicalIndicatorsService->calculateEMA($closePrices, 50);
        $ema200 = $this->technicalIndicatorsService->calculateEMA($closePrices, 200);
    
        // Calculate MACD (12, 26, 9)
        $macd = $this->technicalIndicatorsService->calculateMACD($closePrices);

        if(!$macd)
            return null;
    
        // Calculate ATR for volatility
        $atr = $this->technicalIndicatorsService->calculateATR($highPrices, $lowPrices, $closePrices);
    
        // Identify support and resistance levels
        $supportLevel = min(array_slice($lowPrices, -10)); // Recent low
        $resistanceLevel = max(array_slice($highPrices, -10)); // Recent high
    
        // Entry price
        $entryPrice = $closePrices[$n - 1];
    
        $position = null;
        $stopLoss = null;
        $takeProfit = null;
        $canItradeOnNextCandleStick = false;
    
        // Evaluate trade conditions
        if ($ema50 > $ema200 && $macd['histogram'] > 0) {
            // Long position
            $position = 'Long';
            $stopLoss = max($supportLevel, $entryPrice - (1.5 * $atr));
            $takeProfit = min($resistanceLevel, $entryPrice + (3 * $atr));
                
            if($entryPrice - $stopLoss == 0)
                return null;
            $canItradeOnNextCandleStick = $this->validateRiskReward($entryPrice, $stopLoss, $takeProfit);
        } elseif ($ema50 < $ema200 && $macd['histogram'] < 0) {
            // Short position
            $position = 'Short';
            $stopLoss = min($resistanceLevel, $entryPrice + (1.5 * $atr));
            $takeProfit = max($supportLevel, $entryPrice - (3 * $atr));

            if($entryPrice - $stopLoss == 0)
                return null;
            $canItradeOnNextCandleStick = $this->validateRiskReward($entryPrice, $stopLoss, $takeProfit);
        }
    
        if (!$canItradeOnNextCandleStick) {
            return null; // No valid trade
        }
    
        return [
            'entryPrice' => $entryPrice,
            'stopLoss' => $stopLoss,
            'takeProfit' => $takeProfit,
            'canItradeOnNextCandleStick' => $canItradeOnNextCandleStick,
            'position' => $position
        ];
    }
    
    // Validates the risk-reward ratio
    private function validateRiskReward($entryPrice, $stopLoss, $takeProfit)
    {
        if ($stopLoss === null || $takeProfit === null || $entryPrice === null) {
            return false;
        }


        $riskToReward = abs($takeProfit - $entryPrice) / abs($entryPrice - $stopLoss);
    
        return $riskToReward >= self::RISK_REWARD_RATIO;
    }
}