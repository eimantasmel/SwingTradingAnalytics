<?php

namespace App\Service;

use DOMDocument;
use DOMXPath;
use DateTime;
use DomNode;

class YahooWebScrapService
{
    private const YAHOO_URL = "https://finance.yahoo.com/quote";
    private const MAXIMUM_AMOUNT_TO_THE_NEAREST_DATE = 10;

    private const OPEN_PRICE_COLUMN = 3;
    private const HIGH_PRICE_COLUMN = 4;
    private const LOW_PRICE_COLUMN = 5;
    private const CLOSE_PRICE_COLUMN = 6;
    private const VOLUME_COLUMN = 8;



    private MathService $mathService;

    public function __construct(MathService $mathService) {
        $this->mathService = $mathService;
    }

    /**
     * Fetch stock data (sector and industry) from Yahoo Finance.
     */
    public function getStockData(string $ticker): array
    {
        $url = $this->buildProfileUrl($ticker); 
        $html = $this->getContentFromUrl($url);

        return $this->parseStockData($html);
    }

    private function getStockDataByDates(string $ticker, array $dates): array
    {
        $url = $this->buildHistoryUrl($ticker, $dates[0]);
        $html = $this->getContentFromUrl($url);

        // Parse HTML with DOMDocument and XPath
        $xpath = $this->createXPathFromHtml($html);

        $data = [];

        $data["Dates"] = $dates;

        $data["Open Price"] = array_map(function($date) use ($xpath) {
            return $this->getDataColumnByDateOrClosest($xpath, $date, self::OPEN_PRICE_COLUMN);
        }, $dates);

        $data["High Price"] = array_map(function($date) use ($xpath) {
            return $this->getDataColumnByDateOrClosest($xpath, $date, self::HIGH_PRICE_COLUMN);
        }, $dates);

        $data["Low Price"] = array_map(function($date) use ($xpath) {
            return $this->getDataColumnByDateOrClosest($xpath, $date, self::LOW_PRICE_COLUMN);
        }, $dates);

        $data["Close Price"] = array_map(function($date) use ($xpath) {
            return $this->getDataColumnByDateOrClosest($xpath, $date, self::CLOSE_PRICE_COLUMN);
        }, $dates);

        $data["Volume"] = array_map(function($date) use ($xpath) {
            return $this->getDataColumnByDateOrClosest($xpath, $date, self::VOLUME_COLUMN);
        }, $dates);

        return $data;
    }

    public function getStockDataByDatesByOlderDates($ticker, $startYear, $isCrypto = false)
    {
        $dates = $this->mathService->getDates($startYear, $isCrypto);
        $data = $this->getStockDataByDates($ticker, $dates);

        return $data;
    }
    
    private function createXPathFromHtml(string $html): DOMXPath
    {
        $dom = new DOMDocument();
        libxml_use_internal_errors(true); // Suppress HTML parsing warnings
        @$dom->loadHTML($html);  // Suppress warnings for invalid HTML
        libxml_clear_errors();
        return new DOMXPath($dom);
    }
    
    private function getDataColumnByDateOrClosest(DOMXPath $xpath, string $date, int $column): float
    {
        $row = $this->findRowByDate($xpath, $date);
        if ($row !== null 
                && !in_array('Dividend', explode(' ', $row->nodeValue))
                && !in_array('Splits', explode(' ', $row->nodeValue))) {

            return $this->extractColumnFromRow($row, $column);
        }
        return $this->getClosestNextPrice($xpath, $date, $column);
    }
    
    private function findRowByDate(DOMXPath $xpath, string $date): ?DOMNode
    {
        $query = sprintf("//tr[td[1][contains(text(), '%s')]]", $date);
        // $query = sprintf("//tr[td[normalize-space(text())='%s']]", $date);

        $row = $xpath->query($query);


        return $row->length > 0 ? $row->item(0) : null;
    }
    
    private function getClosestNextPrice(DOMXPath $xpath, string $date, int $column): float
    {
        for ($i = 0; $i < self::MAXIMUM_AMOUNT_TO_THE_NEAREST_DATE; $i++) {
            $date = $this->increaseDateByOneDay($date);
            $row = $this->findRowByDate($xpath, $date);
            if ($row !== null && !in_array('Dividend', explode(' ', $row->nodeValue))) {
                return $this->extractColumnFromRow($row, $column);
            }
        }
        return 0.0;
    }
    
    private function extractColumnFromRow(DOMNode $row, int $column): float
    {
        $priceOfStock = explode(' ', $row->nodeValue)[$column]; // Adjust this if necessary
        return  (float) str_replace(',', '', $priceOfStock);
    }
    
    private function increaseDateByOneDay(string $dateStr): string
    {
        $date = DateTime::createFromFormat('M j, Y', $dateStr);
        $date->modify('+1 day');
        return htmlspecialchars($date->format('M j, Y'), ENT_QUOTES, 'UTF-8');
    }
    

    /**
     * Build the profile URL for the given stock ticker.
     */
    private function buildProfileUrl(string $ticker): string
    {
        return sprintf("%s/%s/profile/?p=%s", self::YAHOO_URL, $ticker, $ticker);
    }

    /**
     * Build the historical data URL for the given stock ticker.
     */
    private function buildHistoryUrl(string $ticker, string $date, string $endDate = null): string
    {
        if(!$endDate)
            $endDate = time();
        else
            $endDate = strtotime($endDate);
        return sprintf("%s/%s/history/?p=%s&period1=%s&period2=%s", self::YAHOO_URL, $ticker, $ticker, strtotime($date), $endDate);
    }


    // private function getContentFromUrl(string $url): string
    // {
    //     // Define the URL for the Express server
    //     $url = $_ENV['EXPRESS_SERVER_URL'] . '?url=' . urlencode($url);

    //     // Initialize cURL session
    //     $ch = curl_init($url);

    //     // Set cURL options
    //     curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); // Return the response as a string
    //     curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true); // Follow redirects if any

    //     // Execute the cURL request
    //     $response = curl_exec($ch);

    //     // Close cURL session
    //     curl_close($ch);

    //     // Return the response (optional)
    //     return $response;
    // }
    /**
     * Fetch the content from the Yahoo Finance page.
     */
    private function getContentFromUrl(string $url): string
    {
        $path = dirname(__DIR__, 2). '\cookies\exported-cookies.json';
        $cookies = json_decode(file_get_contents($path), true);

        // Prepare cookie string for cURL
        $cookieString = '';
        foreach ($cookies as $cookie) {
            $cookieString .= $cookie['name'] . '=' . $cookie['value'] . '; ';
        }

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36",
            "Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8",
            "Accept-Language: en-US,en;q=0.5",
            "Connection: keep-alive",
            "Cookie: $cookieString" // Add cookies to the request
        ]);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);

        $html = curl_exec($ch);
        curl_close($ch);

        return $html ?: '';
    }

    /**
     * Parse the HTML and extract the sector and industry.
     */
    private function parseStockData(string $html): array
    {
        $dom = new DOMDocument();
        @$dom->loadHTML($html);  // Suppress warnings for invalid HTML

        $xpath = new DOMXPath($dom);

        $sector = $this->getNodeValue($xpath, "//dt[contains(text(),'Sector:')]/following-sibling::dd/a", 'Sector not found');
        $industry = $this->getNodeValue($xpath, "//dt[contains(text(),'Industry:')]/following-sibling::a[1]", 'Industry not found');

        return [
            'sector' => $sector,
            'industry' => $industry
        ];
    }

    /**
     * Get the value of the node from XPath or a fallback message.
     */
    private function getNodeValue(DOMXPath $xpath, string $query, string $fallback): string
    {
        $nodeList = $xpath->query($query);
        return ($nodeList->length > 0) ? trim($nodeList->item(0)->nodeValue) : $fallback;
    }
}
