<?php

namespace App\Service;

use App\Service\MathService;
use App\Service\Nasdaq2000IndexService;
use App\Entity\CandleStick;

use InvalidArgumentException;

class TechnicalIndicatorsService
{
    private const SCALING_CONSTANT = 100;
    private const TAX_PER_SHARE = 0.005;


    private Nasdaq2000IndexService $nasdaq2000IndexService;
    private MathService $mathService;

    public function __construct(Nasdaq2000IndexService $nasdaq2000IndexService,
                                MathService $mathService) {
        $this->nasdaq2000IndexService = $nasdaq2000IndexService;
        $this->mathService = $mathService;
    }

    public function detectCandlestickPattern(array $candlesticks)
    {
        $lastCandle = $candlesticks[count($candlesticks) - 1];
        $open = $lastCandle->getOpenPrice();
        $close = $lastCandle->getClosePrice();
        if ($close > $open) {
            return 'bullish';  // Bullish candlestick pattern (e.g., Engulfing, etc.)
        } elseif ($close < $open) {
            return 'bearish';  // Bearish candlestick pattern (e.g., Engulfing, etc.)
        }
        return 'neutral';
    }

    public function calculateSpread(array $candlesticks)
    {
        $atr = $this->calculateATR($candlesticks);
        $averageVolume = $this->getAverageCandleStickVolume($candlesticks);

        if(!$averageVolume)
            return 0;

        return $this->mathService->randomFloat(0, (self::SCALING_CONSTANT/$averageVolume**0.5) * $atr);
    }

    public function calculateATR(array $candleSticks, $period = 14)
    {
        $trueRanges = [];
        $lastCandleSticks = array_slice($candleSticks, -$period);

        for ($i = 1; $i < count($lastCandleSticks); $i++) {
            $trueRanges[] = max($lastCandleSticks[$i]->getHighestPrice() - $lastCandleSticks[$i]->getLowestPrice(), 
                                abs($lastCandleSticks[$i]->getHighestPrice() - $lastCandleSticks[$i - 1]->getClosePrice()), 
                                abs($lastCandleSticks[$i]->getLowestPrice() - $lastCandleSticks[$i - 1]->getClosePrice()));
        }
        return array_sum($trueRanges) / count($trueRanges);
    }

    /**
     * @param CandleStick[] $candlesticks
     */
    public function getAverageCandleStickVolume(array $candleSticks) : float
    {
        $sum = 0;
        foreach ($candleSticks as $candleStick) {
            $volume = $candleStick->getVolume();
            $sum += $volume;
        }

        return $sum / count($candleSticks);
    }

    public function calculateEMA($prices, $period)
    {
        $k = 2 / ($period + 1);
        $ema = [];
        $ema[] = array_sum(array_slice($prices, 0, $period)) / $period;  // SMA for first value
        for ($i = $period; $i < count($prices); $i++) {
            $ema[] = ($prices[$i] - end($ema)) * $k + end($ema);
        }
        return end($ema);  // Return the most recent EMA
    }

    public function calculateMACD(array $prices): array
    {
        if (count($prices) < 26) {
            return null;
        }
    
        // Calculate the 12-period EMA and 26-period EMA for MACD Line
        $ema12 = $this->calculateEMA($prices, 12);
        $ema26 = $this->calculateEMA($prices, 26);
    
        // MACD Line is the difference between the 12-period EMA and 26-period EMA
        $macdLine = $ema12 - $ema26;
    
        // Prepare the MACD line data for Signal Line calculation
        $macdLineArray = [];
        for ($i = 25; $i < count($prices); $i++) {
            $ema12Value = $this->calculateEMA(array_slice($prices, 0, $i + 1), 12);
            $ema26Value = $this->calculateEMA(array_slice($prices, 0, $i + 1), 26);
            $macdLineArray[] = $ema12Value - $ema26Value;
        }
    
        // Signal Line is a 9-period EMA of the MACD Line
        $signalLine = $this->calculateEMA($macdLineArray, 9);
    
        // Histogram is the difference between MACD Line and Signal Line
        $histogram = $macdLine - $signalLine;
    
        return [
            'macdLine' => $macdLine,
            'signalLine' => $signalLine,
            'histogram' => $histogram,
        ];
    }

    public function calculateTaxFee($sharesAmount, $tradeCapital) : float
    {
        $taxFee = max(1, $sharesAmount * self::TAX_PER_SHARE);  // It means minimum 1 dollar
        return min(0.01 * $tradeCapital, $taxFee);      // It means maximums is 0.01 of tradeCapital
    }

    public function calculateSMA(array $prices, int $n = 50): ?float {
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

    /**
     * @param CandleStick[] $candlesticks
     */
    public function getAverageCandleStickRange(array $candleSticks) : float
    {
        $sum = 0;
        foreach ($candleSticks as $candleStick) {
            $highestPrice = $candleStick->getHighestPrice();
            $lowestPrice = $candleStick->getLowestPrice();
            $sum += $highestPrice - $lowestPrice;
        }

        return $sum / count($candleSticks);
    }

    public function calculateRSI(array $candlesticks, int $period = 14): float
    {
        $candlesticksCount = count($candlesticks);

        if ($candlesticksCount < $period + 1) {
            throw new InvalidArgumentException("Not enough candlesticks to calculate RSI for the given period.");
        }

        $gains = [];
        $losses = [];

        // Calculate average gain/loss for the initial period
        for ($i = $candlesticksCount - $period - 1; $i < $candlesticksCount - 1; $i++) {
            $currentClose = $candlesticks[$i + 1]->getClosePrice();
            $previousClose = $candlesticks[$i]->getClosePrice();
            $change = $currentClose - $previousClose;

            if ($change > 0) {
                $gains[] = $change;
                $losses[] = 0;
            } else {
                $gains[] = 0;
                $losses[] = abs($change);
            }
        }

        $averageGain = array_sum($gains) / $period;
        $averageLoss = array_sum($losses) / $period;

        // Calculate the last change (newest candlestick)
        $lastClose = $candlesticks[$candlesticksCount - 1]->getClosePrice();
        $secondLastClose = $candlesticks[$candlesticksCount - 2]->getClosePrice();
        $lastChange = $lastClose - $secondLastClose;

        $gain = $lastChange > 0 ? $lastChange : 0;
        $loss = $lastChange < 0 ? abs($lastChange) : 0;

        // Update average gain/loss using smoothing
        $averageGain = (($averageGain * ($period - 1)) + $gain) / $period;
        $averageLoss = (($averageLoss * ($period - 1)) + $loss) / $period;

        // Calculate RSI
        if ($averageLoss == 0) {
            return 100; // RSI is 100 if there are no losses
        }

        $rs = $averageGain / $averageLoss;
        return 100 - (100 / (1 + $rs));
    }

    /**
     * @param CandleStick[] $candlesticks
     */
    public function calculateATROfTheCandleStick($candleStick, $previousCandleStick) : float
    {
        $highLow = (float)$candleStick->getHighestPrice() - (float)$candleStick->getLowestPrice();
        $highPreviousClose = abs((float)$candleStick->getHighestPrice() - (float)$previousCandleStick->getClosePrice());
        $previousCloseLow = abs((float)$previousCandleStick->getClosePrice() - (float)$candleStick->getLowestPrice());

        return max($highLow, $highPreviousClose, $previousCloseLow);
    }

    public function convertDailyCandlesIntoPeriod(array $dailyCandlesticks, int $period = 5) : array
    {
        $weeklyCandlesticks = [];
        $weeklyHigh = null;
        $weeklyLow = null;
        $weeklyOpen = null;
        $weeklyClose = null;
        $weeklyVolume = 0;
        $weeklyDate = null;;


        $counter = 0;
        foreach ($dailyCandlesticks as $candlestick) {
            /** @var CandleStick $candlestick */

            if(!$weeklyHigh)        // Verify whether it is the first candlestick of the period.
            {
                $weeklyDate = $candlestick->getDate();
                $weeklyHigh = (float)$candlestick->getHighestPrice();
                $weeklyLow = (float)$candlestick->getLowestPrice();
                $weeklyOpen = (float)$candlestick->getOpenPrice();
                $weeklyClose = (float)$candlestick->getClosePrice();
                $weeklyVolume = (float)$candlestick->getVolume();
                $counter++;

                continue;
            }

            if($candlestick->getHighestPrice() > $weeklyHigh)
                $weeklyHigh > $candlestick->getHighestPrice();

            if($candlestick->getLowestPrice() < $weeklyLow)
                $weeklyLow = $candlestick->getLowestPrice();

            $weeklyClose = (float)$candlestick->getClosePrice();
            $weeklyVolume += (float)$candlestick->getVolume();


            if(++$counter % $period == 0)
            {
                $tempCandlestick = new CandleStick();
                $tempCandlestick->setHighestPrice((string)$weeklyHigh);
                $tempCandlestick->setLowestPrice((string)$weeklyLow);
                $tempCandlestick->setOpenPrice((string)$weeklyOpen);
                $tempCandlestick->setClosePrice((string)$weeklyClose);
                $tempCandlestick->setVolume((string)$weeklyVolume);
                $tempCandlestick->setDate($weeklyDate);

                $weeklyCandlesticks[] = $tempCandlestick;

                $weeklyHigh = null;
                $weeklyLow = null;
                $weeklyOpen = null;
                $weeklyClose = null;
                $weeklyVolume = 0;
            }
        }

        if($counter % $period != 0)
        {
            $tempCandlestick = new CandleStick();
            $tempCandlestick->setHighestPrice((string)$weeklyHigh);
            $tempCandlestick->setLowestPrice((string)$weeklyLow);
            $tempCandlestick->setOpenPrice((string)$weeklyOpen);
            $tempCandlestick->setClosePrice((string)$weeklyClose);
            $tempCandlestick->setVolume((string)$weeklyVolume);
            $tempCandlestick->setDate($weeklyDate);

            $weeklyCandlesticks[] = $tempCandlestick;
        }

        return $weeklyCandlesticks;
    }

    public function getHighestPriceByPeriod(array $candleSticks, $period = 20)
    {
        $candlesticksCount = count($candleSticks);
        $highestPrice = 0;

        // Calculate average gain/loss for the initial period
        for ($i = $candlesticksCount - $period - 1; $i < $candlesticksCount; $i++) {
            $price = $candleSticks[$i]->getHighestPrice();

            if($price > $highestPrice)
                $highestPrice = $price;
        }

        return $highestPrice;
    }

    public function getLowestPriceByPeriod(array $candleSticks, $period = 20, $includeLastCandleStick = true)
    {
        $candlesticksCount = count($candleSticks);
        $lowestPrice = 0;

        $lastCandleStick = $includeLastCandleStick ? 0 : 1;

        // Calculate average gain/loss for the initial period
        for ($i = $candlesticksCount - $period - 1; $i < $candlesticksCount - $lastCandleStick; $i++) {
            $price = $candleSticks[$i]->getLowestPrice();

            if($price > $lowestPrice || !$lowestPrice)
                $lowestPrice = $price;
        }

        return $lowestPrice;
    }
}