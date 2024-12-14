<?php

namespace App\Constants;

class Industries
{
    public const INDUSTRIES = [
        'Advertising',
        'Aerospace',
        'Agricultural Chemicals',
        'Agriculture',
        'Airlines',
        'Apparel Manufacturing',
        'Apparel Retail',
        'Asset Management',
        'Auto Parts',
        'Automobiles',
        'Banking',
        'Beverages',
        'Biotechnology',
        'Broadcasting',
        'Building Materials',
        'Chemicals',
        'Cloud Computing',
        'Commercial Services',
        'Communication Equipment',
        'Construction',
        'Consumer Electronics',
        'Consumer Finance',
        'Consumer Goods',
        'Consumer Services',
        'Containers & Packaging',
        'Cosmetics',
        'Department Stores',
        'Discount Stores',
        'Diversified Financials',
        'Diversified Industrials',
        'Drug Manufacturers',
        'Education Services',
        'Electric Utilities',
        'Electronic Components',
        'Electronics',
        'Energy',
        'Entertainment',
        'Environmental Services',
        'Food & Beverage',
        'Food Distribution',
        'Footwear',
        'Furniture',
        'Gaming',
        'Gas Utilities',
        'General Retail',
        'Gold',
        'Health Care',
        'Health Care Equipment',
        'Health Care Providers',
        'Health Technology',
        'Home Furnishings',
        'Home Improvement Retail',
        'Hotels',
        'Household Products',
        'Industrial Machinery',
        'Industrial Products',
        'Information Technology',
        'Insurance',
        'Internet Content & Information',
        'Investment Banking',
        'IT Services',
        'Leisure',
        'Life Sciences',
        'Logistics',
        'Luxury Goods',
        'Machinery',
        'Media',
        'Metals & Mining',
        'Mortgage Finance',
        'Movie Production',
        'Oil & Gas',
        'Oilfield Services',
        'Pharmaceuticals',
        'Publishing',
        'Railroads',
        'Real Estate',
        'Recreational Goods',
        'Renewable Energy',
        'Restaurants',
        'Retail',
        'Semiconductors',
        'Shipping',
        'Software',
        'Specialty Retail',
        'Telecommunications',
        'Textiles',
        'Tobacco',
        'Tourism',
        'Transportation',
        'Travel Services',
        'Utilities',
        'Video Games',
        'Warehousing',
        'Waste Management',
        'Water Utilities',
        'Wholesale',
        'Wireless Communications',
    ];

    public static function findClosestIndustry(string $input): ?string
    {
        $closestIndustry = null;
        $shortestDistance = PHP_INT_MAX;

        foreach (self::INDUSTRIES as $industry) {
            // Calculate the Levenshtein distance between the input and the current industry
            $distance = levenshtein(strtolower($input), strtolower($industry));

            // Update the closest match if this distance is shorter
            if ($distance < $shortestDistance) {
                $shortestDistance = $distance;
                $closestIndustry = $industry;
            }
        }

        return $closestIndustry;
    }

}
