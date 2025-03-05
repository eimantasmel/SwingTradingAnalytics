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


use DateTime;
/**
 * 
 */
class SpyETFSARStrategy implements SwingTradingStrategyInterface
{
    private const MIN_AMOUNT_OF_MONEY = 20;

    private const AMOUNT_OF_PREVIOUS_CANDLESTICKS = 720;
    private const AMOUNT_OF_NEXT_CANDLESTICKS = 100;

    private const MAX_AMOUNT_TRADES_PER_DAY = 10;

    private const TARGET_PERCENTAGE = 0.02;

    private const CAPITAL_RISK = 0.1;

    private const TRADES_REVISION_AMOUNT = 30;


    private const PYRAMIDING_TRADES_AMOUNT = 1;

    private const EFT_TICKER = 'SPY';

    private array $trade_information = [];
    private float $riskCapital = 0;
    private array $results = [];
    private float $maxDrawdown;
    private float $highestCapitalValue;
    private array $lastTradesInformation = [];

    private int $lossStreak = 0;
    private int $winStreak = 0;
    private float $lastTradingCapital = 0;
    private float $lastCapitalRisk = 0.1;
    private const MIN_WIN_STREAK = 3;
    private const MIN_LOSS_STREAK = 2;
    private const CAPITAL_CHANGE = 0.03;
    private const MAX_CAPITAL_CHANGE = 0.2;
    private const MIN_CAPITAL_CHANGE = 0.02;

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
        $securities = $this->entityManager->getRepository(Security::class)->findBy(['ticker' => self::EFT_TICKER]);

        $this->results = [];
        $this->maxDrawdown = 0;
        $this->highestCapitalValue = $tradingCapital;
        $this->results[BaseConstants::HIGHEST_CAPITAL] = $tradingCapital;
        $this->results[BaseConstants::MAX_DRAWDOWN] = 0;
        $this->results[BaseConstants::AMOUNT_OF_TRADES] = 0;

        
        $startDate = new DateTime($startDate);
        $endDate = new DateTime($endDate);

        $lastTradesAmount = $this->results[BaseConstants::AMOUNT_OF_TRADES];

        $this->riskCapital = self::CAPITAL_RISK * $tradingCapital;
        $this->lastTradingCapital = $tradingCapital;
        $this->lastCapitalRisk = self::CAPITAL_RISK;
        
        while($startDate < $endDate)
        {
            if($this->results[BaseConstants::AMOUNT_OF_TRADES] % self::TRADES_REVISION_AMOUNT == 0 
            && $lastTradesAmount != $this->results[BaseConstants::AMOUNT_OF_TRADES]
          )
            {
                $lastTradesAmount = $this->results[BaseConstants::AMOUNT_OF_TRADES];
                $this->riskCapital = self::CAPITAL_RISK * $tradingCapital;
                $this->lastTradingCapital = $tradingCapital;
                $this->lastCapitalRisk = self::CAPITAL_RISK;
            }

            // It's skips weekends because in our database there's only few cryptos.
            if($startDate->format('N') >= 6)
            {
                $startDate->modify('+1 day');
                continue;
            }

            $tradingCapital = $this->processPyramidingTrades($startDate, $tradingCapital);

            if(count($this->lastTradesInformation) >= self::PYRAMIDING_TRADES_AMOUNT)
            {
                $randomDateInterval = (int)mt_rand(4, 16);
                $startDate->modify("+{$randomDateInterval} days");
                continue;
            }

            $riskCapital = $this->riskCapital;
            
            $tradingCapital = $this->getTradingCapitalAfterDay($startDate, $securities, $tradingCapital, $riskCapital);

            // $randomDateInterval = (int)mt_rand(2, 4);
            // $startDate->modify("+{$randomDateInterval} days");
            $startDate->modify("+1 day");


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

    private function getTradingCapitalAfterDay(DateTime $tradingDate, array $securities, float $tradingCapital, float $riskCapital)
    {
        shuffle($securities);
        $tradesCounter = 0;
        foreach ($securities as $security) {
            $lastCandleSticks = $security->getLastNCandleSticks($tradingDate, self::AMOUNT_OF_PREVIOUS_CANDLESTICKS);
            // echo "Date: " . $tradingDate->format('Y-m-d') . $security->getTicker() . "\n\r";
            if($this->isSecurityEligibleForTrading($lastCandleSticks, $tradingDate, $security))
            {
                $enterPrice = $this->trade_information[BaseConstants::TRADE_ENTER_PRICE];
                $spread = $this->technicalIndicatorsService->calculateSpread($lastCandleSticks);
                $spread = $this->trade_information[BaseConstants::TRADE_POSITION] == "Long" ? $spread : -1 * $spread;
                $enterPrice += $spread;
                
                $sharesAmount = $this->getSharesAmount($tradingCapital, $enterPrice);

                $tradeCapital = $this->getTradeCapital($tradingCapital, $enterPrice, $sharesAmount);
                // Checks whether do we have enough money to afford this trade
                if(!$tradeCapital)
                    continue;

                $tradingCapitalBeforeTrade = $tradingCapital;

                $newTradingDate = new DateTime($this->trade_information[BaseConstants::TRADE_DATE]);


                $tradingCapitalAfterTrade = $this->getProfit($security, 
                                                            $sharesAmount, 
                                                            $newTradingDate
                                                            );


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
                    $this->addTradingDataInformation(BaseConstants::IS_WINNER, true);
                else 
                    $this->addTradingDataInformation(BaseConstants::IS_WINNER, false);

                $this->results[BaseConstants::TRADES_INFORMATION][] = $this->trade_information;
                $this->lastTradesInformation[] = $this->trade_information;

                $tradingCapital = $tradingCapitalBeforeTrade;

                if(++$tradesCounter >= self::MAX_AMOUNT_TRADES_PER_DAY || $tradingCapital < self::MIN_AMOUNT_OF_MONEY)
                    return $tradingCapital;


                if(count($this->lastTradesInformation) >= self::PYRAMIDING_TRADES_AMOUNT)
                    return $tradingCapital;

            }
        }

        return $tradingCapital;
    }
    
    private function isSecurityEligibleForTrading(array $lastCandleSticks, DateTime $tradingDate, Security $security) : bool
    {
        $lastCandleStick = $this->getLastCandleStick($lastCandleSticks);
        $nextCandleSticks = $security->getNextNCandleSticks($tradingDate, 20);
        
        $sar = $this->technicalIndicatorsService->calculateSAR($lastCandleSticks);
        $position = "Long";

        if($lastCandleStick->getClosePrice() > $sar)
            $position = "Short";

        foreach ($nextCandleSticks as $candleStick) {
            $previousCandlesticks = $security->getLastNCandleSticks($candleStick->getDate(), self::AMOUNT_OF_PREVIOUS_CANDLESTICKS);
            $sar = $this->technicalIndicatorsService->calculateSAR($previousCandlesticks);

            if($position == "Long" && $candleStick->getClosePrice() > $sar)
            {
                $this->addTradingDataInformation(BaseConstants::TRADE_POSITION, "Long");
                $this->addTradingDataInformation(BaseConstants::TRADE_STOP_LOSS_PRICE, 0);
                $this->addTradingDataInformation(BaseConstants::TRADE_TAKE_PROFIT_PRICE, 0);
                $this->addTradingDataInformation(BaseConstants::TRADE_DATE, $candleStick->getDate()->format('Y-m-d'));
                $this->addTradingDataInformation(BaseConstants::TRADE_ENTER_PRICE, $candleStick->getClosePrice());
    
                return true;
            }

            // if($position == "Short" && $candleStick->getClosePrice() < $sar)
            // {
            //     $this->addTradingDataInformation(BaseConstants::TRADE_POSITION, "Short");
            //     $this->addTradingDataInformation(BaseConstants::TRADE_STOP_LOSS_PRICE, 0);
            //     $this->addTradingDataInformation(BaseConstants::TRADE_TAKE_PROFIT_PRICE, 0);
            //     $this->addTradingDataInformation(BaseConstants::TRADE_DATE, $candleStick->getDate()->format('Y-m-d'));
            //     $this->addTradingDataInformation(BaseConstants::TRADE_ENTER_PRICE, $candleStick->getClosePrice());
    
            //     return true;
            // }
        }

        return false;
    }


    private function getLastCandleStick(array $lastCandleSticks)
    {
        return $lastCandleSticks[count($lastCandleSticks) - 1];
    }

    private function getProfit(Security $security, float $sharesAmount, DateTime $tradingDate) : float 
    {
        $this->addTradingDataInformation(BaseConstants::TRADE_DATE, $tradingDate->format('Y-m-d'));
        $this->addTradingDataInformation(BaseConstants::TRADE_SECURITY_TICKER, $security->getTicker());
        $this->addTradingDataInformation(BaseConstants::TRADE_STOP_LOSS_PRICE, 0);

        $nextCandleSticks = $security->getNextNCandleSticks($tradingDate, self::AMOUNT_OF_NEXT_CANDLESTICKS);
        $position = $this->trade_information[BaseConstants::TRADE_POSITION];

        $enterPrice = $this->trade_information[BaseConstants::TRADE_ENTER_PRICE];
        $target = $position == "Long" ? $enterPrice * 1.02 : $enterPrice * 0.98;

        $shares = $sharesAmount;
        $capital = 0;

        foreach ($nextCandleSticks as $candleStick) {
            if($candleStick->getDate() == $tradingDate)
            {
                continue;
            }


            /** @var CandleStick $candleStick */
            $closePrice = $candleStick->getClosePrice();
            $lowestPrice = $candleStick->getLowestPrice();
            $exitDate = $candleStick->getDate();
            

            $previousCandlesticks = $security->getLastNCandleSticks($candleStick->getDate(), self::AMOUNT_OF_PREVIOUS_CANDLESTICKS);
            $sar = $this->technicalIndicatorsService->calculateSAR($previousCandlesticks);

            $last10CandleSticks = $security->getLastNCandleSticks($exitDate, 10);
            $spread = $this->technicalIndicatorsService->calculateSpread($last10CandleSticks);
            $spread = 0;

            // if($position == "Long" && $closePrice > $target)
            // {
            //     $shares = $shares / 2;
            //     $capital += $shares * ($closePrice - $spread);
            //     $target = 1.02 * $closePrice;
            // }

            // if($position == "Short" && $closePrice < $target)
            // {
            //     $shares = $shares / 2;
            //     $capital += $shares * ($closePrice - $spread);
            //     $target = 0.98 * $closePrice;
            // }

            if($position == "Long" && $sar > $closePrice)
            {
                // so that means that you should leave your position 30 minutes before market close let's say
                $this->addTradingDataInformation(BaseConstants::TRADE_EXIT_PRICE, $closePrice - $spread);
                $this->addTradingDataInformation(BaseConstants::EXIT_DATE, $exitDate->format('Y-m-d'));
                $this->addTradingDataInformation(BaseConstants::TRADE_RISK_REWARD, null);

                return ($closePrice - $spread) * $sharesAmount;
            }

            if($position == "Short" && $sar < $closePrice)
            {
                // so that means that you should leave your position 30 minutes before market close let's say
                $this->addTradingDataInformation(BaseConstants::TRADE_EXIT_PRICE, $closePrice - $spread);
                $this->addTradingDataInformation(BaseConstants::EXIT_DATE, $exitDate->format('Y-m-d'));
                $this->addTradingDataInformation(BaseConstants::TRADE_RISK_REWARD, null);

                return ($closePrice - $spread) * $sharesAmount;
            }
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

    private function getSharesAmount($riskCapital, $enterPrice) : float
    {
        $sharesAmount = (float)($riskCapital / $enterPrice);
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

                if($this->winStreak >= self::MIN_WIN_STREAK && $this->lastCapitalRisk < self::MAX_CAPITAL_CHANGE)
                {
                    $this->lastCapitalRisk += self::CAPITAL_CHANGE;
                    $this->riskCapital = $this->lastCapitalRisk * $this->lastTradingCapital;
                }

                if($this->lossStreak >= self::MIN_LOSS_STREAK && $this->lastCapitalRisk > self::MIN_CAPITAL_CHANGE)
                {
                    $this->lastCapitalRisk -= self::CAPITAL_CHANGE;
                    $this->riskCapital = $this->lastCapitalRisk * $this->lastTradingCapital;
                }

                if($this->winStreak < self::MIN_WIN_STREAK && $this->lossStreak < self::MIN_LOSS_STREAK)
                {
                    $this->riskCapital = self::CAPITAL_RISK * $this->lastTradingCapital;
                    $this->lastCapitalRisk = self::CAPITAL_RISK;
                }

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
        // I will fix this later with position, because now Long position is hardcoded
        if($this->isSecurityEligibleForTrading($lastCandleSticks, $tradingDate, $security))
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
}