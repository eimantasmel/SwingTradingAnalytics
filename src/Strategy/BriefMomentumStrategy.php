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
/** I decided to make this strategy oriented only on crypto assets. 
 *  First and for most it checks the general crypto market. Is it on uptrend.
 *  And if it is then it looks for uptrending cryptos with rsi at least 70 rsi.
 */
class BriefMomentumStrategy implements SwingTradingStrategyInterface
{
    private const MIN_AMOUNT_OF_MONEY = 20;

    private const PYRAMIDING_TRADES_AMOUNT = 6;

    private const AMOUNT_OF_PREVIOUS_CANDLESTICKS = 700;
    private const AMOUNT_OF_NEXT_CANDLESTICKS = 100;
    private const MIN_VOLUME = 1_000_000;
    private const CAPITAL_RISK = 0.03;
    private const MAX_AMOUNT_TRADES_PER_DAY = 1;
    private const MIN_RSI = 70;

    private const MIN_PRICE = 0.0001;

    private array $trade_information = [];
    private array $lastTradesInformation = [];
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
        $securities = $this->getOnlyStocks($securities);
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


            $nasdaqIndex = $this->entityManager->getRepository(Security::class)->findOneBy(['ticker' => $this->marketIndex->getTicker()]);
            $lastNasdaqMarketCandleSticks = $nasdaqIndex->getLastNCandleSticks($startDate, self::AMOUNT_OF_PREVIOUS_CANDLESTICKS);
            $lastNasdaqCandleStick = $this->getLastCandleStick($lastNasdaqMarketCandleSticks);
            $nasdaqMarketPrices = $this->extractClosingPricesFromCandlesticks($lastNasdaqMarketCandleSticks);
            $sma200 = $this->technicalIndicatorsService->calculateSMA($nasdaqMarketPrices, 200);
            $sma50 = $this->technicalIndicatorsService->calculateSMA($nasdaqMarketPrices, 50);
            // It means that nasdaq market has to be on the bull run.
            if((float)$lastNasdaqCandleStick->getClosePrice() <= $sma200 || $sma50 < $sma200)
            {
                $startDate->modify('+1 day');
                continue;
            }
    

            $tradingCapital = $this->getTradingCapitalAfterDay($startDate, $securities, $tradingCapital);

            $randomDateInterval = (int)mt_rand(1, 4);
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
        $this->results[BaseConstants::FINAL_TRADING_CAPITAL] = $tradingCapital;
        $this->sortTradesInfomationExitDates();

        return $this->results;
    }

    private function getTradingCapitalAfterDay(DateTime $tradingDate, array $securities, float $tradingCapital)
    {
        shuffle($securities);
        $tradesCounter = 0;
        foreach ($securities as $security) {
            // skips nasdaq2000 overall market security
            if($security->getTicker() == $this->marketIndex->getTicker()
                || !$this->isTradeable($security->getTicker())
                || $security->getIsForex()
              )
                continue;

            $lastCandleSticks = $security->getLastNCandleSticks($tradingDate, self::AMOUNT_OF_PREVIOUS_CANDLESTICKS);
            echo "Date: " . $tradingDate->format('Y-m-d') . $security->getTicker() . "\n\r";
            if($this->isSecurityEligibleForTrading($lastCandleSticks, $security, $tradingDate))
            {
                $lastCandleStick = $this->getLastCandleStick($lastCandleSticks);

                $enterPrice = $lastCandleStick->getClosePrice();      // I'm do this because 
                $stopLoss = $this->trade_information[BaseConstants::TRADE_STOP_LOSS_PRICE];
                $sharesAmount = $this->getSharesAmount($tradingCapital, $stopLoss, $enterPrice);

                $spread = $this->technicalIndicatorsService->calculateSpread($lastCandleSticks);
                
                $enterPrice += $spread;
                $tradeCapital = $this->getTradeCapital($tradingCapital, $enterPrice, $sharesAmount);

                if(!$tradeCapital)
                    continue;

                $tradingCapitalBeforeTrade = $tradingCapital;
                $tradingCapitalAfterTrade = $this->getProfit($security, 
                                                            $sharesAmount, 
                                                            $tradingDate, 
                                                            $spread, 
                                                            $enterPrice);

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
    
    private function isSecurityEligibleForTrading(array $lastCandleSticks, $security, $tradingDate) : bool
    {
        $period = 5;
        if($security->getIsCrypto())
            $period = 7;

        $weeklyCandleSticks = $this->technicalIndicatorsService->convertDailyCandlesIntoPeriod($lastCandleSticks, $period);
        if(!$weeklyCandleSticks || count($weeklyCandleSticks) < 15)     // 15 is the minimum amount of candlesticks to calculate rsi
            return false;
        
        $prices = $this->extractClosingPricesFromCandlesticks($lastCandleSticks);
        $sma200 = $this->technicalIndicatorsService->calculatesma($prices, 200);

        $lastWeeklyCandleStick = $this->getLastCandleStick($weeklyCandleSticks);
        $secondLastWeeklyCandleStick = $weeklyCandleSticks[count($weeklyCandleSticks) - 2];


        $closePrice = $lastWeeklyCandleStick->getClosePrice();

        $rsi = $this->technicalIndicatorsService->calculateRSI($weeklyCandleSticks);
        $atr14 = $this->technicalIndicatorsService->calculateATR($weeklyCandleSticks, 14);

        $earliestDate = clone $tradingDate;
        $earliestDate->modify('-1 month');
        $priceOneMonthBefore = $security->getCandleStickByDate($earliestDate)->getClosePrice();

        if($closePrice == 1)
            return false;

        $volume = $lastWeeklyCandleStick->getVolume();


        if($volume < self::MIN_VOLUME || $closePrice < self::MIN_PRICE)
            return false;

        if($closePrice > $sma200 
            && $rsi > self::MIN_RSI
            && $secondLastWeeklyCandleStick->getClosePrice() > $secondLastWeeklyCandleStick->getOpenPrice()
            && $closePrice /$priceOneMonthBefore - 1 > 0.2
          )
        {
            $this->addTradingDataInformation(BaseConstants::TRADE_POSITION, "Long");
            $this->addTradingDataInformation(BaseConstants::TRADE_STOP_LOSS_PRICE, $closePrice - 0.6 * $atr14);
            $this->addTradingDataInformation(BaseConstants::TRADE_TAKE_PROFIT_PRICE, $closePrice + $atr14);
            return true;
        }

        return false;
    }

    private function getLastCandleStick(array $lastCandleSticks)
    {
        return $lastCandleSticks[count($lastCandleSticks) - 1];
    }

    private function getProfit(Security $security, $sharesAmount, DateTime $tradingDate, float $spread, float $enterPrice) : float 
    {
        $this->addTradingDataInformation(BaseConstants::TRADE_DATE, $tradingDate->format('Y-m-d'));
        $this->addTradingDataInformation(BaseConstants::TRADE_SECURITY_TICKER, $security->getTicker());
        $this->addTradingDataInformation(BaseConstants::TRADE_POSITION, 'Long');

        $period = 5;
        if($security->getIsCrypto())
            $period = 7;

        $stopLoss = $this->trade_information[BaseConstants::TRADE_STOP_LOSS_PRICE];


        $nextCandleSticks = $security->getNextNCandleSticks($tradingDate, self::AMOUNT_OF_NEXT_CANDLESTICKS);
        $nextWeeklyCandleSticks = $this->technicalIndicatorsService->convertDailyCandlesIntoPeriod($nextCandleSticks, $period);
        $target = $this->trade_information[BaseConstants::TRADE_TAKE_PROFIT_PRICE];

        $initialPrice = $enterPrice;
        foreach ($nextWeeklyCandleSticks as $candleStick) {
            if($candleStick->getDate() == $tradingDate)
            {
                $previousCandleStick = $candleStick;
                continue;
            }

            /** @var CandleStick $candleStick */
            $closePrice = $candleStick->getClosePrice();
            $highestPrice = $candleStick->getHighestPrice();

            
            $exitDate = $candleStick->getDate();

            $last10CandleSticks = $security->getLastNCandleSticks($exitDate, 10);
            $spread = $this->technicalIndicatorsService->calculateSpread($last10CandleSticks);
            $spread = $this->trade_information[BaseConstants::TRADE_POSITION] == "Long" ? $spread : -1 * $spread;

            if($highestPrice >= $target)        // That's a looser.
            {
                // so that means that you should leave your position 30 minutes before market close let's say
                $this->addTradingDataInformation(BaseConstants::TRADE_EXIT_PRICE, $target - $spread);
                $this->addTradingDataInformation(BaseConstants::EXIT_DATE, $exitDate->format('Y-m-d'));

                $riskReward = ($target  - $initialPrice) / ($initialPrice - $this->trade_information[BaseConstants::TRADE_STOP_LOSS_PRICE] / 0.3);
                $this->addTradingDataInformation(BaseConstants::TRADE_RISK_REWARD, $riskReward);

                return ($target - $spread) * $sharesAmount;
            }

            if($closePrice <= $stopLoss)
            {
                $this->addTradingDataInformation(BaseConstants::TRADE_RISK_REWARD, null);
                $this->addTradingDataInformation(BaseConstants::TRADE_EXIT_PRICE, $closePrice - $spread);
                $this->addTradingDataInformation(BaseConstants::EXIT_DATE, $exitDate->format('Y-m-d'));
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

    private function isTradeable($ticker) : bool
    {
        foreach ($this->lastTradesInformation as $key => $tradeInformation) {
            if ($tradeInformation[BaseConstants::TRADE_SECURITY_TICKER] == $ticker) {
                return false;
            }
        }
        return true;
    }

    public function canITrade(Security $security, DateTime $tradingDate) : bool
    {
        $lastCandleSticks = $security->getLastNCandleSticks($tradingDate, self::AMOUNT_OF_PREVIOUS_CANDLESTICKS);
        // echo "Date: " . $tradingDate->format('Y-m-d') . $security->getTicker() . "\n\r";
        if($this->isSecurityEligibleForTrading($lastCandleSticks, $security, $tradingDate))
        {
            return true;
        }

        return false;
    }

    public function shouldIExit(Security $security, $stopLoss, $sharesAmount, DateTime $tradingDate, float $enterPrice, array $nextCandleSticks) : bool 
    {
        return false;
    }

    private function sortTradesInfomationExitDates()
    {
        $tradesInformation = $this->results[BaseConstants::TRADES_INFORMATION];
        usort($tradesInformation, function($a, $b) {
            return strtotime($a[BaseConstants::EXIT_DATE]) <=> strtotime($b[BaseConstants::EXIT_DATE]); // Ascending order
        });

        $this->results[BaseConstants::TRADES_INFORMATION] = $tradesInformation;
    }

    private function getOnlyStocks($securities)
    {
        $stocks = [];
        foreach ($securities as $security) {
            /** @var Security $security */
            if(!$security->getIsForex())
                $stocks[] = $security;
        }

        return $stocks;
    }
}