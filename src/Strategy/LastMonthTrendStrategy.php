<?php

namespace App\Strategy;


use App\Entity\Security;
use App\Entity\CandleStick;
use App\Interface\SwingTradingStrategyInterface;
use App\Constants\BaseConstants;
use App\Service\TechnicalIndicatorsService;
use Doctrine\ORM\EntityManagerInterface;
use App\Interface\MarketIndexInterface;
use App\Service\IndustryAnalysisService;
use App\Constants\Industries;


use DateTime;
/**
 * 
 */
class LastMonthTrendStrategy implements SwingTradingStrategyInterface
{
    private const MIN_AMOUNT_OF_MONEY = 20;

    private const AMOUNT_OF_PREVIOUS_CANDLESTICKS = 720;
    private const AMOUNT_OF_NEXT_CANDLESTICKS = 100;
    private const MIN_VOLUME = 1_000_000;
    private const CAPITAL_RISK = 0.08;
    private const MAX_AMOUNT_TRADES_PER_DAY = 1;

    private const MIN_AMOUNT_OF_CANDLESTICKS = 200;
    private const MIN_PRICE = 20;
    private const AMOUNT_OF_TREND_DAYS = 100;

    // if results still be poor increase till 20 percent. and reduce the minimum price in order to find trades faster.
    private const MIN_GROWTH_CAGR = 0.15;

    private const PYRAMIDING_TRADES_AMOUNT = 6;

    private const MAX_LOSS_STREAK = 4;      // After this streak we will simulate pause.
    private const MIN_WIN_STREAK = 2;       // After this we can trade again


    private array $trade_information = [];
    private array $results = [];
    private float $maxDrawdown;
    private float $highestCapitalValue;
    private array $lastTradesInformation = [];

    private int $lossStreak = 0;
    private int $winStreak = 0;
    private bool $pause = false;


    private TechnicalIndicatorsService $technicalIndicatorsService;
    private EntityManagerInterface $entityManager;
    private MarketIndexInterface $marketIndexInterface;
    private IndustryAnalysisService $industryAnalysisService;

    public function __construct(TechnicalIndicatorsService $technicalIndicatorsService,
                                EntityManagerInterface $entityManager,
                                MarketIndexInterface $marketIndexInterface,
                                IndustryAnalysisService $industryAnalysisService
                               ) 
    {
        $this->technicalIndicatorsService = $technicalIndicatorsService;
        $this->entityManager = $entityManager;
        $this->marketIndexInterface = $marketIndexInterface;
        $this->industryAnalysisService = $industryAnalysisService;
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
     * TODO: So now you have to adapt this strategy on short usage as well, but firstly run montecarlo simulation on 5
     * iterations and find out whether this strategy is profitable, because most of them are not.
     */
    public function getSimulationData(string $startDate, 
                                      string $endDate,
                                      float $tradingCapital,
                                      array $securities
                                     ) : array
    {
        $securities = $this->getOnlyStocks($securities);
        $this->results = [];
        $this->maxDrawdown = 0;
        $this->highestCapitalValue = $tradingCapital;
        $this->results[BaseConstants::HIGHEST_CAPITAL] = $tradingCapital;
        $this->results[BaseConstants::MAX_DRAWDOWN] = 0;
        $this->results[BaseConstants::AMOUNT_OF_TRADES] = 0;
        $this->lossStreak = 0;
        $this->winStreak = 0;
        $this->pause = false;

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

            $marketIndex = $this->entityManager->getRepository(Security::class)->findOneBy(['ticker' => $this->marketIndexInterface->getTicker()]);
            $lastMarketIndexCandleSticks = $marketIndex->getLastNCandleSticks($startDate, self::AMOUNT_OF_PREVIOUS_CANDLESTICKS);
            $lastMarketCandleStick = $this->getLastCandleStick($lastMarketIndexCandleSticks);
    
            $marketIndexPrices = $this->extractClosingPricesFromCandlesticks($lastMarketIndexCandleSticks);
            $sma200Market = $this->technicalIndicatorsService->calculateSMA($marketIndexPrices, 200);
            $sma50Market = $this->technicalIndicatorsService->calculateSMA($marketIndexPrices, 50);
            $closePriceMarket = $lastMarketCandleStick->getClosePrice();

            $position = null;
            if($closePriceMarket > $sma200Market && $sma50Market > $sma200Market && $closePriceMarket > $sma50Market)
            {
                $position = 'Long';
            }
            else if($closePriceMarket < $sma200Market && $sma50Market < $sma200Market && $closePriceMarket < $sma50Market)
            {
                $position = 'Short';

            }
            else
            {
                $startDate->modify('+1 day');
                continue;
            }

            $tradingCapital = $this->getTradingCapitalAfterDay($startDate, $securities, $tradingCapital, $position);

            $randomDateInterval = (int)mt_rand(2, 4);
            $startDate->modify("+{$randomDateInterval} days");
            // $startDate->modify("+1 day");


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

        $tradingCapital = $this->processPyramidingTrades($startDate, $tradingCapital, true);
        $this->sortTradesInfomationExitDates();
        $this->results[BaseConstants::FINAL_TRADING_CAPITAL] = $tradingCapital;
        return $this->results;
    }

    private function getTradingCapitalAfterDay(DateTime $tradingDate, array $securities, float $tradingCapital, string $position)
    {
        shuffle($securities);
        $tradesCounter = 0;
        foreach ($securities as $security) {
            if($security->getTicker() == $this->marketIndexInterface->getTicker()
            || !$this->isTradeable($security->getTicker())
            || $security->getIsForex())
                continue;


            $lastCandleSticks = $security->getLastNCandleSticks($tradingDate, self::AMOUNT_OF_PREVIOUS_CANDLESTICKS);
            // echo "Date: " . $tradingDate->format('Y-m-d') . $security->getTicker() . "\n\r";
            if($this->isSecurityEligibleForTrading($lastCandleSticks, $security, $position, $tradingDate))
            {
                $lastCandleStick = $this->getLastCandleStick($lastCandleSticks);
                $enterPrice = $lastCandleStick->getClosePrice();      // I'm do this because 
                $stopLoss = $this->trade_information[BaseConstants::TRADE_STOP_LOSS_PRICE];
                
                $sharesAmount = $this->getSharesAmount($tradingCapital, $stopLoss, $enterPrice);

                $spread = $this->technicalIndicatorsService->calculateSpread($lastCandleSticks);
                $spread = $this->trade_information[BaseConstants::TRADE_POSITION] == "Long" ? $spread : -1 * $spread;

                $enterPrice += $spread;
                $tradeCapital = $this->getTradeCapital($tradingCapital, $enterPrice, $sharesAmount);
                // Checks whether do we have enough money to afford this trade
                if(!$tradeCapital)
                    continue;

                $tradingCapitalBeforeTrade = $tradingCapital;


                $tradingCapitalAfterTrade = $this->getProfit($security, 
                                                            $stopLoss, 
                                                            $sharesAmount, 
                                                            $tradingDate, 
                                                            $enterPrice,
                                                            $position);


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

                $tradeIncome = $tradingCapital - $tradingCapitalBeforeTrade;

                $this->addTradingDataInformation(BaseConstants::TRADING_CAPITAL, $tradeIncome);

                if($tradingCapitalBeforeTrade < $tradingCapital)
                {
                    $this->addTradingDataInformation(BaseConstants::IS_WINNER, true);
                }
                else 
                {
                    $this->addTradingDataInformation(BaseConstants::IS_WINNER, false);
                }

                $this->results[BaseConstants::TRADES_INFORMATION][] = $this->trade_information;
                $this->lastTradesInformation[] = $this->trade_information;

                $tradingCapital = $tradingCapitalBeforeTrade;

                if(++$tradesCounter >= self::MAX_AMOUNT_TRADES_PER_DAY || $tradingCapital < self::MIN_AMOUNT_OF_MONEY)
                    return $tradingCapital;
            }
        }

        return $tradingCapital;
    }
    
    private function isSecurityEligibleForTrading(array $lastCandleSticks, Security $security, string $position, DateTime $date) : bool
    {
        if(count($lastCandleSticks) < self::MIN_AMOUNT_OF_CANDLESTICKS)    
            return false;

        $lastCandleStick = $this->getLastCandleStick($lastCandleSticks);
        $closePrice = $lastCandleStick->getClosePrice();
        $volume = $lastCandleStick->getVolume();

        if($closePrice < self::MIN_PRICE || $volume < self::MIN_VOLUME)
            return false;


        $atr14 = $this->technicalIndicatorsService->calculateATR($lastCandleSticks, 14);
        $earliestDate = clone $date;
        $earliestDate->modify('-14 days');
        $priceOneMonthBefore = $security->getCandleStickByDate($earliestDate)->getClosePrice();

        $prices = $this->extractClosingPricesFromCandlesticks($lastCandleSticks);
        $sma200 = $this->technicalIndicatorsService->calculateSMA($prices, 200);


        if($position == 'Long' 
           && $closePrice / $priceOneMonthBefore - 1 >= self::MIN_GROWTH_CAGR 
           && $closePrice > $sma200
          )
        {
            $this->addTradingDataInformation(BaseConstants::TRADE_POSITION, "Long");
            $stopLoss = $closePrice - 0.7 * $atr14;
            $target = $closePrice + $atr14;
            $this->addTradingDataInformation(BaseConstants::TRADE_STOP_LOSS_PRICE, $stopLoss);
            $this->addTradingDataInformation(BaseConstants::TRADE_TAKE_PROFIT_PRICE, $target);
            return true;
        }


        if($position == 'Short'
           && $closePrice / $priceOneMonthBefore - 1 < -1 * self::MIN_GROWTH_CAGR 
           && $closePrice < $sma200
          )
        {
            $this->addTradingDataInformation(BaseConstants::TRADE_POSITION, "Short");
            $stopLoss = $closePrice +  0.7 * $atr14;
            $target = $closePrice - $atr14;
            $this->addTradingDataInformation(BaseConstants::TRADE_STOP_LOSS_PRICE, $stopLoss);
            $this->addTradingDataInformation(BaseConstants::TRADE_TAKE_PROFIT_PRICE, $target);

            return true;
        }


        return false;
    }

    private function getLastCandleStick(array $lastCandleSticks)
    {
        return $lastCandleSticks[count($lastCandleSticks) - 1];
    }

    private function getProfit(Security $security, $stopLoss, float $sharesAmount, DateTime $tradingDate, float $enterPrice, string $position) : float 
    {
        $this->addTradingDataInformation(BaseConstants::TRADE_DATE, $tradingDate->format('Y-m-d'));
        $this->addTradingDataInformation(BaseConstants::TRADE_SECURITY_TICKER, $security->getTicker());
        $this->addTradingDataInformation(BaseConstants::TRADE_STOP_LOSS_PRICE, $stopLoss);

        $nextCandleSticks = $security->getNextNCandleSticks($tradingDate, self::AMOUNT_OF_NEXT_CANDLESTICKS);
        $target = $this->trade_information[BaseConstants::TRADE_TAKE_PROFIT_PRICE];
        foreach ($nextCandleSticks as $candleStick) {

            if($candleStick->getDate() == $tradingDate)
            {
                continue;
            }

            // TODO: try run montecarlo simulation one more time and if results still be bad then try focus not on sectors with high
            // last month growth but on individual securities.
            // run montecarlo with 5 iterations 

            /** @var CandleStick $candleStick */
            $closePrice = $candleStick->getClosePrice();
            $lowestPrice = $candleStick->getLowestPrice();
            $highestPrice = $candleStick->getHighestPrice();
            $exitDate = $candleStick->getDate();

            $last10CandleSticks = $security->getLastNCandleSticks($exitDate, 10);
            $spread = $this->technicalIndicatorsService->calculateSpread($last10CandleSticks);
            $spread = $position == "Long" ? $spread : -1 * $spread;


            if($highestPrice > $target && $position == "Long")
            {
                $exitDate = $candleStick->getDate()->format('Y-m-d');
                $closePrice = $candleStick->getClosePrice();
                $this->addTradingDataInformation(BaseConstants::EXIT_DATE, $exitDate);
                $this->addTradingDataInformation(BaseConstants::TRADE_EXIT_PRICE, $target - $spread);

                $riskRewardRatio = ($target - $enterPrice - $spread) / ($enterPrice - ($stopLoss - 5 * $spread)) ;
                $this->addTradingDataInformation(BaseConstants::TRADE_RISK_REWARD, $riskRewardRatio);
        
                return ($target - $spread) * $sharesAmount;
            }


            if($closePrice < $stopLoss && $position == "Long")
            {
                $exitDate = $candleStick->getDate()->format('Y-m-d');
                $closePrice = $candleStick->getClosePrice();
                $this->addTradingDataInformation(BaseConstants::EXIT_DATE, $exitDate);
                $this->addTradingDataInformation(BaseConstants::TRADE_EXIT_PRICE, $closePrice - $spread);
                $this->addTradingDataInformation(BaseConstants::TRADE_RISK_REWARD, null);
        
                return ($closePrice - $spread) * $sharesAmount;
            }


            /** -------------------------------------------SHORT----------------------------------------- */

            if($closePrice > $stopLoss && $position == "Short")
            {
                $exitDate = $candleStick->getDate()->format('Y-m-d');
                $closePrice = $candleStick->getClosePrice();
                $this->addTradingDataInformation(BaseConstants::EXIT_DATE, $exitDate);
                $this->addTradingDataInformation(BaseConstants::TRADE_EXIT_PRICE, $closePrice - $spread);
                $this->addTradingDataInformation(BaseConstants::TRADE_RISK_REWARD, null);
        
                return ($closePrice - $spread) * $sharesAmount;
            }

            if($lowestPrice < $target && $position == "Short")
            {
                $exitDate = $candleStick->getDate()->format('Y-m-d');
                $closePrice = $candleStick->getClosePrice();
                $this->addTradingDataInformation(BaseConstants::EXIT_DATE, $exitDate);
                $this->addTradingDataInformation(BaseConstants::TRADE_EXIT_PRICE, $target - $spread);
                $this->addTradingDataInformation(BaseConstants::TRADE_RISK_REWARD, null);
        
                return ($target - $spread) * $sharesAmount;
            }
        }

        $exitDate = $candleStick->getDate()->format('Y-m-d');
        $closePrice = $candleStick->getClosePrice();
        $this->addTradingDataInformation(BaseConstants::EXIT_DATE, $exitDate);
        $this->addTradingDataInformation(BaseConstants::TRADE_EXIT_PRICE, $closePrice - $spread);
        $this->addTradingDataInformation(BaseConstants::TRADE_RISK_REWARD, null);

        return ($candleStick->getClosePrice() - $spread) * $sharesAmount;
    }

    private function getTradeCapital($tradingCapital, $enterPrice, $sharesAmount)
    {
        if($enterPrice * $sharesAmount > $tradingCapital * 3)   // i will replace this at later point.
            return false;

        return $sharesAmount * $enterPrice;
    }

    private function getSharesAmount($tradingCapital, $stopLoss, $enterPrice) : float
    {
        $riskCapital = $tradingCapital * self::CAPITAL_RISK;
        $sharesAmount = (float)($riskCapital / (abs($enterPrice - $stopLoss)));

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

    private function checkWhetherIsStrongUptrend(Security $security, array $candleSticks) : bool
    {
        for ($i=0; $i < count($candleSticks); $i++) { 
            $candleStick = $candleSticks[$i];

            if(count($candleSticks) - $i > self::AMOUNT_OF_TREND_DAYS)  
                continue;

            /** @var CandleStick $candleStick */
            $lastCandleSticks = $security->getLastNCandleSticks($candleStick->getDate(), self::AMOUNT_OF_PREVIOUS_CANDLESTICKS);
            $prices = $this->extractClosingPricesFromCandlesticks($lastCandleSticks);
            $sma200 = $this->technicalIndicatorsService->calculateSMA($prices, 200);

            if($candleStick->getClosePrice() < $sma200)
                return false;
        }

        return true;
    }

    private function checkWhetherIsStrongDowntrend(Security $security, array $candleSticks) : bool
    {
        for ($i=0; $i < count($candleSticks); $i++) { 
            $candleStick = $candleSticks[$i];

            if(count($candleSticks) - $i > self::AMOUNT_OF_TREND_DAYS)  
                continue;

            /** @var CandleStick $candleStick */
            $lastCandleSticks = $security->getLastNCandleSticks($candleStick->getDate(), self::AMOUNT_OF_PREVIOUS_CANDLESTICKS);
            $prices = $this->extractClosingPricesFromCandlesticks($lastCandleSticks);
            $sma200 = $this->technicalIndicatorsService->calculateSMA($prices, 200);

            if($candleStick->getClosePrice() > $sma200)
                return false;
        }

        return true;
    }

    private function getLowestPrice(array $candleSticks)
    {
        $lowestPrice = (float)$candleSticks[0]->getLowestPrice();
        foreach ($candleSticks as $candleStick) {
            if((float)$candleStick->getLowestPrice() < $lowestPrice)
                $lowestPrice = (float)$candleStick->getLowestPrice();
        }

        return (float)$lowestPrice;
    }

    private function getHighestPrice(array $candleSticks)
    {
        $highestPrice = (float)$candleSticks[0]->getHighestPrice();
        foreach ($candleSticks as $candleStick) {
            if((float)$candleStick->getHighestPrice() > $highestPrice)
                $highestPrice = (float)$candleStick->getHighestPrice();
        }

        return (float)$highestPrice;
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

    private function removeTradeInformationFromResults($tradeInformation)
    {
        for ($i=0; $i < count($this->results[BaseConstants::TRADES_INFORMATION]); $i++) { 
            if($this->results[BaseConstants::TRADES_INFORMATION][$i] == $tradeInformation)
            {
                unset($this->results[BaseConstants::TRADES_INFORMATION][$i]); // Remove element
                break;
            }
        }
    }

    private function sortTradesInfomationExitDates()
    {
        $tradesInformation = $this->results[BaseConstants::TRADES_INFORMATION];
        usort($tradesInformation, function($a, $b) {
            return strtotime($a[BaseConstants::EXIT_DATE]) <=> strtotime($b[BaseConstants::EXIT_DATE]); // Ascending order
        });

        $this->results[BaseConstants::TRADES_INFORMATION] = $tradesInformation;
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

    private function processPyramidingTrades(DateTime $date, $tradingCapital, $finishAll = false)
    {
        // first you need to sort them by exit date ascennding
        usort($this->lastTradesInformation, function($a, $b) {
            return strtotime($a[BaseConstants::EXIT_DATE]) <=> strtotime($b[BaseConstants::EXIT_DATE]); // Ascending order
        });

        foreach ($this->lastTradesInformation as $key => $tradeInformation) {
            if (strtotime($tradeInformation[BaseConstants::EXIT_DATE]) <= strtotime($date->format('Y-m-d')) || $finishAll) {
                if($tradeInformation[BaseConstants::TRADING_CAPITAL] > 0)
                {
                    $this->winStreak++;
                    $this->lossStreak = 0;
                }
                else
                {
                    $this->lossStreak++;
                    $this->winStreak = 0;
                }

                if(!$this->pause)
                {
                    $tradingCapital += $tradeInformation[BaseConstants::TRADING_CAPITAL];
                    $this->updateResultTradingInformation($tradingCapital, $tradeInformation);
                }
                else 
                {
                    $this->removeTradeInformationFromResults($tradeInformation);
                }


                if($this->lossStreak >= self::MAX_LOSS_STREAK)
                    $this->pause = true;
                if($this->winStreak >= self::MIN_WIN_STREAK)
                    $this->pause = false;

                unset($this->lastTradesInformation[$key]); // Remove element
            }
        }

        return $tradingCapital;
    }

    public function canITrade(Security $security, DateTime $tradingDate) : bool
    {
        $lastCandleSticks = $security->getLastNCandleSticks($tradingDate, self::AMOUNT_OF_PREVIOUS_CANDLESTICKS);
        // echo "Date: " . $tradingDate->format('Y-m-d') . $security->getTicker() . "\n\r";
        // I will fix this later with position, because now Long position is hardcoded
        if($this->isSecurityEligibleForTrading($lastCandleSticks, $security, "Long", $tradingDate))
        {
            $stopLoss = $this->trade_information[BaseConstants::TRADE_STOP_LOSS_PRICE];
            echo "Stop loss is: " . $stopLoss  . "\n\r";
            return true;
        }

        return false;
    }

    public function shouldIExit(Security $security, $stopLoss, $sharesAmount, DateTime $tradingDate, float $enterPrice, array $nextCandleSticks, $position = "Long") : bool 
    {
        $previousCandleStick = null;
        $firstTargetReach = false;
        foreach ($nextCandleSticks as $candleStick) {
            if($candleStick->getDate() == $tradingDate)
            {
                $previousCandleStick = $candleStick;
                continue;
            }

            /** @var CandleStick $candleStick */
            $closePrice = $candleStick->getClosePrice();
            $lowestPrice = $candleStick->getLowestPrice();


            $exitDate = $candleStick->getDate();

            $last10CandleSticks = $security->getLastNCandleSticks($exitDate, 10);
            $spread = $this->technicalIndicatorsService->calculateSpread($last10CandleSticks);
            $spread = $position == "Long" ? $spread : -1 * $spread;

            if($position == "Long")
            {
                if($lowestPrice <= $stopLoss && $firstTargetReach)       // we can consider this as a winner but it might a be a looser
                {
                    return true;
                }
    
                if($lowestPrice <= $stopLoss)        // That's a looser.
                {
                    return true;
                }
    
                if($closePrice < $previousCandleStick->getClosePrice() && $closePrice > $enterPrice)    // we updating stop loss
                {
                    $firstTargetReach = true;
                    $enterPrice = $closePrice;
                    $last5CandleSticks = $security->getLastNCandleSticks($candleStick->getDate(), 5);
                    $atr5 = $this->technicalIndicatorsService->calculateATR($last5CandleSticks, 5);
                    $stopLoss = $closePrice - 2 * $atr5;
                    echo "New stop loss is: " . $stopLoss . "\r\n";
                }
            }
            else        // Position is -------short-------
            {       
                if($closePrice >= $stopLoss && $firstTargetReach)       // we can consider this as a winner but it might a be a looser
                {
                    return true;
                }
    
                if($closePrice >= $stopLoss)        // That's a looser.
                {
                    return true;
                }
    
                if($closePrice > $previousCandleStick->getClosePrice() && $closePrice < $enterPrice)    // we updating stop loss
                {
                    $firstTargetReach = true;
                    $enterPrice = $closePrice;
                    $last5CandleSticks = $security->getLastNCandleSticks($candleStick->getDate(), 5);
                    $atr5 = $this->technicalIndicatorsService->calculateATR($last5CandleSticks, 5);
                    $stopLoss = $closePrice + 2 * $atr5;
                }
            }

            $previousCandleStick = $candleStick;
        }

        return false;
    }

    private function getOnlyStocks($securities)
    {
        $stocks = [];
        foreach ($securities as $security) {
            /** @var Security $security */
            if(!$security->getIsCrypto() && !$security->getIsForex())
                $stocks[] = $security;
        }

        return $stocks;
    }
}