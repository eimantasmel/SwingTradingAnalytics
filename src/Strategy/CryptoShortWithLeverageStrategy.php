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
use App\Repository\CandleStickRepository;

/**
 * 
 */
class CryptoShortWithLeverageStrategy implements SwingTradingStrategyInterface
{
    private const MIN_AMOUNT_OF_MONEY = 20;
    private const MIN_AMOUNT_OF_CANDLESTICKS = 10;

    private const AMOUNT_OF_PREVIOUS_CANDLESTICKS = 720;
    private const AMOUNT_OF_NEXT_CANDLESTICKS = 60;      // N -1 NEXT TRADING DAYS AMOUNT
    private const MIN_VOLUME = 500_000;

    private const CAPITAL_RISK = 0.1;
    private const UNFORTUNATE_SPREAD_PROBABILITY = .55;

    private const LEVERAGE = 1;
    private const RISK_REWARD = 0.5;
    private const GROWTH_PERCENTAGE = 1;        //  100 percent growth per candle

    private const PYRAMIDING_TRADES_AMOUNT = 10;
    private const MAX_AMOUNT_TRADES_PER_DAY = 1;
    
    private array $trade_information = [];
    private array $results = [];
    private float $maxDrawdown;
    private float $highestCapitalValue;
    private array $lastTradesInformation = [];

    private TechnicalIndicatorsService $technicalIndicatorsService;
    private EntityManagerInterface $entityManager;
    private MarketIndexInterface $marketIndexInterface;
    private IndustryAnalysisService $industryAnalysisService;
    private CandleStickRepository $candleStickRepository;

    public function __construct(TechnicalIndicatorsService $technicalIndicatorsService,
                                EntityManagerInterface $entityManager,
                                MarketIndexInterface $marketIndexInterface,
                                IndustryAnalysisService $industryAnalysisService,
                                CandleStickRepository $candleStickRepository
                               ) 
    {
        $this->technicalIndicatorsService = $technicalIndicatorsService;
        $this->entityManager = $entityManager;
        $this->marketIndexInterface = $marketIndexInterface;
        $this->industryAnalysisService = $industryAnalysisService;
        $this->candleStickRepository = $candleStickRepository;
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
        $this->results = [];
        $this->maxDrawdown = 0;
        $this->highestCapitalValue = $tradingCapital;
        $this->results[BaseConstants::HIGHEST_CAPITAL] = $tradingCapital;
        $this->results[BaseConstants::MAX_DRAWDOWN] = 0;
        $this->results[BaseConstants::AMOUNT_OF_TRADES] = 0;

        $startDate = new DateTime($startDate);
        $endDate = new DateTime($endDate);

        $spikedCandleSticks = $this->candleStickRepository->getCandleSticksWithHugeGrowthSpike($startDate, $endDate);

        foreach($spikedCandleSticks as $candleStick)
        {
            $random = (int)mt_rand(1, 2);

            if($random > 1)
                continue;

            $tradingCapital = $this->processPyramidingTrades($candleStick->getDate(), $tradingCapital);

            if(count($this->lastTradesInformation) == self::PYRAMIDING_TRADES_AMOUNT)
            {
                continue;
            }

            $position = 'Short';
            
            $tradingCapital = $this->getTradingCapitalAfterDay($candleStick, $tradingCapital, $position);

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

    private function getTradingCapitalAfterDay(CandleStick $candleStick, float $tradingCapital, string $position)
    {
        $tradesCounter = 0;
        $security = $candleStick->getSecurity();
        $tradingDate = $candleStick->getDate();

        if($security->getTicker() == $this->marketIndexInterface->getTicker()
            || !$this->isTradeable($security->getTicker())
        )
            return $tradingCapital;

        $lastCandleSticks = $security->getLastNCandleSticks($tradingDate, self::AMOUNT_OF_PREVIOUS_CANDLESTICKS);
        // echo "Date: " . $tradingDate->format('Y-m-d') . $security->getTicker() . "\n\r";
        if($this->isSecurityEligibleForTrading($lastCandleSticks, $security, $position, $tradingDate))
        {
            $enterPrice = $this->trade_information[BaseConstants::TRADE_ENTER_PRICE];     // I'm do this because 
            $stopLoss = $this->trade_information[BaseConstants::TRADE_STOP_LOSS_PRICE];
            $sharesAmount = $this->getSharesAmount($tradingCapital, $stopLoss, $enterPrice);

            // $spread = $this->technicalIndicatorsService->calculateSpread($lastCandleSticks);
            // $spread = $this->trade_information[BaseConstants::TRADE_POSITION] == "Long" ? $spread : -1 * $spread;
            $spread = $this->technicalIndicatorsService->calculateSpread($lastCandleSticks, false, self::UNFORTUNATE_SPREAD_PROBABILITY, $position);
            $spread = 0;

            $enterPrice -= $spread;
            $tradeCapital = $this->getTradeCapital($tradingCapital, $enterPrice, $sharesAmount);
            // Checks whether do we have enough money to afford this trade
            if(!$tradeCapital)
                return $tradingCapital;


            $tradingCapitalBeforeTrade = $tradingCapital;


            $tradingCapitalAfterTrade = $this->getProfit($security, 
                                                        $sharesAmount, 
                                                        $tradingDate, 
                                                        $position
                                                        );

                                                            
    
            $tradingCapital = $tradingCapital - $tradingCapitalAfterTrade + $tradeCapital;

            $this->addTradingDataInformation(BaseConstants::TRADE_ENTER_PRICE, $enterPrice);
            $this->results[BaseConstants::AMOUNT_OF_TRADES]++;

            $taxFee = $this->technicalIndicatorsService->calculateTaxFee($sharesAmount, $tradeCapital);
            $this->addTradingDataInformation(BaseConstants::TRADE_FEE, $taxFee);
            $this->addTradingDataInformation(BaseConstants::TRADE_SPREAD, $spread);

            // $tradingCapital -= $taxFee;  # TODO: because mexc has no fees

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

        return $tradingCapital;
    }
    
    private function isSecurityEligibleForTrading(array $lastCandleSticks, Security $security, string $position, DateTime $tradingDate) : bool
    {
        if(count($lastCandleSticks) < self::MIN_AMOUNT_OF_CANDLESTICKS)    
            return false;



        $nextCandleSticks = $security->getNextNCandleSticks($tradingDate, self::AMOUNT_OF_NEXT_CANDLESTICKS);

        if(count($nextCandleSticks) < 5)    
            return false;


        $lastCandleStick = $this->getLastCandleStick($lastCandleSticks);


        $closePrice = $nextCandleSticks[1]->getClosePrice() - 0.01 * $nextCandleSticks[1]->getClosePrice();

        $volume = $lastCandleStick->getVolume();
        // $averageVolume = $this->technicalIndicatorsService->getAverageCandleStickVolume(array_slice($lastCandleSticks, -30));
        if($volume < self::MIN_VOLUME  || !$closePrice || !$lastCandleStick->getOpenPrice())
            return false;

        if(
            $nextCandleSticks[0]->getClosePrice() / $nextCandleSticks[0]->getOpenPrice() - 1 >= 1
            && $nextCandleSticks[1]->getClosePrice() / $nextCandleSticks[1]->getOpenPrice() - 1 <= -0.1
            && $nextCandleSticks[1]->getClosePrice() / $nextCandleSticks[1]->getOpenPrice() - 1 > -0.4

          )
        {
            $stopLoss = (1 + 1/self::LEVERAGE) * $closePrice;
            $target = $closePrice - self::RISK_REWARD * abs($stopLoss - $closePrice);
            $this->addTradingDataInformation(BaseConstants::TRADE_POSITION, "Short");
            $this->addTradingDataInformation(BaseConstants::TRADE_TAKE_PROFIT_PRICE, $target);
            $this->addTradingDataInformation(BaseConstants::TRADE_STOP_LOSS_PRICE, $stopLoss);
            $this->addTradingDataInformation(BaseConstants::TRADE_DATE, $nextCandleSticks[1]->getDate()->format('Y-m-d'));

            $this->addTradingDataInformation(BaseConstants::TRADE_ENTER_PRICE, $closePrice);


            return true;
        }


        return false;
    }


    private function getLastCandleStick(array $lastCandleSticks)
    {
        return $lastCandleSticks[count($lastCandleSticks) - 1];
    }

    private function getProfit(Security $security, float $sharesAmount, DateTime $tradingDate, string $position) : float 
    {
        $this->addTradingDataInformation(BaseConstants::TRADE_SECURITY_TICKER, $security->getTicker());

        $tradingDate = new DateTime($this->trade_information[BaseConstants::TRADE_DATE]);
        $nextCandleSticks = $security->getNextNCandleSticks($tradingDate, self::AMOUNT_OF_NEXT_CANDLESTICKS);
        $target = $this->trade_information[BaseConstants::TRADE_TAKE_PROFIT_PRICE];
        $stopLoss = $this->trade_information[BaseConstants::TRADE_STOP_LOSS_PRICE];

        $lastClosePrice = null;

        foreach ($nextCandleSticks as $candleStick) {

            if($candleStick->getDate() == $tradingDate)
            {
                continue;
            }

            /** @var CandleStick $candleStick */
            $closePrice = $candleStick->getClosePrice();
            $lastClosePrice = $closePrice;
            $highestPrice = $candleStick->getHighestPrice();
            $lowestPrice = $candleStick->getLowestPrice();
            $exitDate = $candleStick->getDate();

            // $spread = $closePrice * 0.01;
            $spread = 0;


            if($highestPrice >= $stopLoss)
            {
                $exitDate = $candleStick->getDate()->format('Y-m-d');
                $this->addTradingDataInformation(BaseConstants::EXIT_DATE, $exitDate);
                $this->addTradingDataInformation(BaseConstants::TRADE_EXIT_PRICE, $stopLoss + $spread);
                $this->addTradingDataInformation(BaseConstants::TRADE_RISK_REWARD, null);
        
                return ($stopLoss + $spread) * $sharesAmount;
            }
          
            if($lowestPrice <= $target)
            {
                $exitDate = $candleStick->getDate()->format('Y-m-d');
                $closePrice = $candleStick->getClosePrice();
                $this->addTradingDataInformation(BaseConstants::EXIT_DATE, $exitDate);
                $this->addTradingDataInformation(BaseConstants::TRADE_EXIT_PRICE, $target + $spread);
                $this->addTradingDataInformation(BaseConstants::TRADE_RISK_REWARD, null);
        
                return ($target + $spread) * $sharesAmount;
            }
        }

        $exitDate = $candleStick->getDate()->format('Y-m-d');
        // $closePrice = $candleStick->getClosePrice();
        $this->addTradingDataInformation(BaseConstants::EXIT_DATE, $exitDate);
        $this->addTradingDataInformation(BaseConstants::TRADE_EXIT_PRICE, $lastClosePrice - $spread);
        $this->addTradingDataInformation(BaseConstants::TRADE_RISK_REWARD, null);

        return ($lastClosePrice + $spread) * $sharesAmount;
    }

    private function getTradeCapital($tradingCapital, $enterPrice, $sharesAmount)
    {
        if($enterPrice * $sharesAmount > $tradingCapital * 3)   // i will replace this at later point.
            return false;

        return $sharesAmount * $enterPrice * self::LEVERAGE;
    }

    private function getSharesAmount($tradingCapital, $stopLoss, $enterPrice) : float
    {
        $riskCapital = $tradingCapital * self::CAPITAL_RISK;
        $sharesAmount = (float)($riskCapital / (abs($enterPrice - $stopLoss)));

        return $sharesAmount * self::LEVERAGE;
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
            $spread = 0;

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