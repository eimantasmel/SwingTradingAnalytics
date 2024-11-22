<?php

namespace App\Service;

use App\Service\MathService;
use App\Service\Nasdaq2000IndexService;


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
        $closePrices = [];
        $highPrices = [];
        $lowPrices = [];
        foreach ($candlesticks as $candle) {
            $closePrices[] = $candle->getClosePrice();
            $highPrices[] = $candle->getHighestPrice();
            $lowPrices[] = $candle->getLowestPrice();
        }

        $atr = $this->calculateATR($highPrices, $lowPrices, $closePrices);
        $averageVolume = $this->getAverageCandleStickVolume($candlesticks);

        if(!$averageVolume)
            return 0;

        return $this->mathService->randomFloat(0, (self::SCALING_CONSTANT/$averageVolume**0.5) * $atr);
    }

    public function calculateATR($highPrices, $lowPrices, $closePrices)
    {
        $trueRanges = [];
        for ($i = 1; $i < count($highPrices); $i++) {
            $trueRanges[] = max($highPrices[$i] - $lowPrices[$i], abs($highPrices[$i] - $closePrices[$i - 1]), abs($lowPrices[$i] - $closePrices[$i - 1]));
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

}