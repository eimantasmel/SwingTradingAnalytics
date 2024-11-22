<?php

namespace App\Service;

class MathService
{
    public function calculateStandardDeviation(array $numbers) : float
    {
        $count = count($numbers);
        
        if ($count === 0) {
            return 0; // Return 0 if the array is empty to avoid division by zero
        }
    
        // Calculate the mean (average)
        $mean = array_sum($numbers) / $count;
    
        // Calculate the sum of the squared differences from the mean
        $sumOfSquaredDifferences = 0;
        foreach ($numbers as $number) {
            $sumOfSquaredDifferences += pow($number - $mean, 2);
        }
    
        // Calculate the variance
        $variance = $sumOfSquaredDifferences / (float)$count;
    
        // Return the standard deviation (square root of variance)
        return sqrt($variance);
    }


    public function calculateMean(array $numbers) : float
    {
        $count = count($numbers);
        $mean = array_sum($numbers) / (float)$count;
        return $mean;
    }

    public function getNLastDigitsFromTheNumber($number, $numberOfLastDigits = 2) : int 
    {
        $number= intval($number);
        // Use modulo to extract the last two digits
        return $number % (10 ** $numberOfLastDigits);
    }

    public function calculateCagr($numberOfYears, $initialValue, $finalValue) : float
    {
        if($initialValue == 0)
            return 0;

        return ($finalValue / $initialValue) ** (1/$numberOfYears) - 1;
    }

    public function sortAssocArrayDescending($array)
    {
        uasort($array, function($a, $b) {
            if ($a == $b) {
                return 0;
            }
            return ($a > $b) ? -1 : 1; // Descending order
        });

        return $array;
    }

    public function getDates($startYear, $includeWeekends = true)
    {
        $dates = [];
        $date = "{$startYear}-01-01";
        $currentDate = new \DateTime();
        $dateTime = new \DateTime($date);
        while(true)
        {
            if($currentDate <= $dateTime)
                return $dates;

            // Check if the modified date is Saturday or Sunday
            if(!$includeWeekends && $dateTime->format('N') >= 6)
            {
                $dateTime->modify('+1 day');
                continue;
            }

            $dates[] = htmlspecialchars($dateTime->format('M j, Y'), ENT_QUOTES, 'UTF-8');
            $dateTime->modify('+1 day');
        }

        return $dates;
    }

    public function convertDateIntoSpecificFormat($date, $format = 'Y-m-d') : string
    {
        $dateTime = new \DateTime($date);
        $date = htmlspecialchars($dateTime->format($format), ENT_QUOTES, 'UTF-8');

        return $date;
    }

    function randomFloat($min, $max) {
        return $min + mt_rand() / mt_getrandmax() * ($max - $min);
    }
}