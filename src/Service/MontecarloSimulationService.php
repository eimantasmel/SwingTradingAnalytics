<?php

namespace App\Service;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Output\OutputInterface;
use App\Service\MathService;
use App\Entity\Security;
use App\Interface\SwingTradingStrategyInterface;
use DateTime;
use App\Constants\BaseConstants;


class MontecarloSimulationService
{
    // When it will certain amount of attempts will come up with the same combination then it will consider that amount of simulations is insufficient
    private const EXHAUSTION_AMOUNT = 5;    
    private const MIN_AMOUNT_OF_TRADES = 1;
    private array $combinations = []; 
    private const DECIMAL_PLACES_AMOUNT = 3;
    
    
    private EntityManagerInterface $entityManager;
    private SwingTradingStrategyInterface $swingTradingStrategy;
    private MathService $mathService;
    private OutputInterface $output;


    public function __construct(EntityManagerInterface $entityManager,
                                MathService $mathService,
                                SwingTradingStrategyInterface $swingTradingStrategy)
    {
        $this->entityManager = $entityManager;
        $this->mathService = $mathService;
        $this->swingTradingStrategy = $swingTradingStrategy;
    }

    public function runMontecarloSimulation(OutputInterface $output, 
                                            string $startDate,
                                            string $endDate,
                                            int $amountOfIterations,
                                            float $initialTradingCapital) : array
    {
        $this->output = $output;
        $securities = $this->entityManager->getRepository(Security::class)->findAll();

        $results = [];
        $results[BaseConstants::AMOUNT_OF_TRADES] = 0;

        for ($i=0; $i < $amountOfIterations; $i++) { 

            $data = $this->swingTradingStrategy->getSimulationData($startDate,
                                            $endDate,
                                            $initialTradingCapital,
                                            $securities);
            if($data[BaseConstants::AMOUNT_OF_TRADES] >= self::MIN_AMOUNT_OF_TRADES
                && !in_array($data[BaseConstants::FINAL_TRADING_CAPITAL], $this->combinations))
            {
                $results[BaseConstants::FINAL_TRADING_CAPITAL][] = $data[BaseConstants::FINAL_TRADING_CAPITAL];

                $results[BaseConstants::MAX_DRAWDOWN][] = $data[BaseConstants::MAX_DRAWDOWN];
                $results[BaseConstants::HIGHEST_CAPITAL][] = $data[BaseConstants::HIGHEST_CAPITAL];

                $results[BaseConstants::AMOUNT_OF_TRADES] += $data[BaseConstants::AMOUNT_OF_TRADES];
                $results[BaseConstants::TRADES_INFORMATION][] = $data[BaseConstants::TRADES_INFORMATION];

                $results[BaseConstants::AVERAGE_RISK_REWARD_RATIO][] = $this->calculateAverageRiskRewardRatio($data[BaseConstants::TRADES_INFORMATION]);
                $results[BaseConstants::AVERAGE_WIN_PERCENTAGE][] = $this->calculateWinPercentage($data[BaseConstants::TRADES_INFORMATION]);



                $this->combinations[] = $data[BaseConstants::FINAL_TRADING_CAPITAL];
            }
        }



        $this->printTradesInformationData($results[BaseConstants::TRADES_INFORMATION][0]);

        $meanOfFinalTradingCapital = $this->mathService->calculateMean($results[BaseConstants::FINAL_TRADING_CAPITAL]);
        $standartDeviationOfFinalTradingCapital = $this->mathService->calculateStandardDeviation($results[BaseConstants::FINAL_TRADING_CAPITAL]);

        $meanOfRiskRewardRatio = $this->mathService->calculateMean($results[BaseConstants::AVERAGE_RISK_REWARD_RATIO]);
        $standartDeviationOfRiskRewardRatio = $this->mathService->calculateStandardDeviation($results[BaseConstants::AVERAGE_RISK_REWARD_RATIO]);

        $meanOfWinPercengate = $this->mathService->calculateMean($results[BaseConstants::AVERAGE_WIN_PERCENTAGE]);
        $standartDeviationOfWinPercentage = $this->mathService->calculateStandardDeviation($results[BaseConstants::AVERAGE_WIN_PERCENTAGE]);
        
        $meanOfMaxDrawdown = $this->mathService->calculateMean($results[BaseConstants::MAX_DRAWDOWN]);
        $standartDeviationOfMaxDrawdown = $this->mathService->calculateStandardDeviation($results[BaseConstants::MAX_DRAWDOWN]);


        echo "\r\n";
        echo "Final trading capital is:" . $results[BaseConstants::FINAL_TRADING_CAPITAL][0]. "\r\n";
        echo "Highest Capital: " . $results[BaseConstants::HIGHEST_CAPITAL][0] . "\r\n";
        echo "Max Drawdown :" . $results[BaseConstants::MAX_DRAWDOWN][0]. "\r\n";
        echo "Win percentage is: " . $this->calculateWinPercentage($results[BaseConstants::TRADES_INFORMATION][0]) . "\r\n";
        echo "Average fee: " . $this->calculateAverageFeeTax($results[BaseConstants::TRADES_INFORMATION][0]). "\r\n";
        echo "Average risk reward: " . $this->calculateAverageRiskRewardRatio($results[BaseConstants::TRADES_INFORMATION][0]). "\r\n";

        echo "General Information about the all iterations:" . "\r\n";
        echo "Number of iterations: "  . $amountOfIterations . "\r\n";

        echo "Average of final trading capital: ".  $meanOfFinalTradingCapital . "\r\n";
        echo "Standart Deviation of final trading capital: ".  $standartDeviationOfFinalTradingCapital . "\r\n";

        echo "\r\n";

        echo "Average of risk reward ratio: ".  $meanOfRiskRewardRatio . "\r\n";
        echo "Standart Deviation of risk reward ratio: ".  $standartDeviationOfRiskRewardRatio . "\r\n";

        echo "\r\n";

        echo "Average of risk win percentage: ".  $meanOfWinPercengate . "\r\n";
        echo "Standart Deviation of win percentage: ".  $standartDeviationOfWinPercentage . "\r\n";

        echo "\r\n";

        echo "Average of max drawdown".  $meanOfMaxDrawdown . "\r\n";
        echo "Standart Deviation of max drawdown".  $standartDeviationOfMaxDrawdown . "\r\n";


        return $results;
    }

    private function printTradesInformationData(array $tradesInformation) 
    {
        $this->output->writeln('');
        $this->output->writeln('');

        // Define column headers and widths
        $headers = ['#', 'Trade Date', 'Exit Date', 'Ticker', 'Enter Price', 'Stop Loss', 'Take Profit', 'Exit Price', 'Winner', 'Capital', 'Position', 'Spread'];
        $columnWidths = [4, 12, 12, 7, 12, 12, 12, 12, 8, 12, 10, 6];
    
        // Print header row with alignment
        $headerRow = '|';
        foreach ($headers as $index => $header) {
            $headerRow .= str_pad($header, $columnWidths[$index], ' ', STR_PAD_BOTH) . '|';
        }
        $this->output->writeln($headerRow);
    
        // Print a separator row
        $separatorRow = '+' . str_repeat('-', array_sum($columnWidths) + count($columnWidths) - 1) . '+';
        $this->output->writeln($separatorRow);
    
        // Print each row of trade information
        $counter = 0;
        foreach ($tradesInformation as $tradeInformation) {
            $row = '|';
            $row .= str_pad(++$counter, $columnWidths[0], ' ', STR_PAD_BOTH) . '|';
            $row .= str_pad($tradeInformation[BaseConstants::TRADE_DATE], $columnWidths[1], ' ', STR_PAD_BOTH) . '|';
            $row .= str_pad($tradeInformation[BaseConstants::EXIT_DATE], $columnWidths[2], ' ', STR_PAD_BOTH) . '|';
            $row .= str_pad($tradeInformation[BaseConstants::TRADE_SECURITY_TICKER], $columnWidths[3], ' ', STR_PAD_BOTH) . '|';
            $row .= str_pad(number_format($tradeInformation[BaseConstants::TRADE_ENTER_PRICE], self::DECIMAL_PLACES_AMOUNT), $columnWidths[4], ' ', STR_PAD_LEFT) . '|';
            $row .= str_pad(number_format($tradeInformation[BaseConstants::TRADE_STOP_LOSS_PRICE], self::DECIMAL_PLACES_AMOUNT), $columnWidths[5], ' ', STR_PAD_LEFT) . '|';
            $row .= str_pad(number_format($tradeInformation[BaseConstants::TRADE_TAKE_PROFIT_PRICE], self::DECIMAL_PLACES_AMOUNT), $columnWidths[6], ' ', STR_PAD_LEFT) . '|';
            $row .= str_pad(number_format($tradeInformation[BaseConstants::TRADE_EXIT_PRICE], self::DECIMAL_PLACES_AMOUNT), $columnWidths[7], ' ', STR_PAD_LEFT) . '|';
            $row .= str_pad($tradeInformation[BaseConstants::IS_WINNER] ? 'Yes' : 'No', $columnWidths[8], ' ', STR_PAD_BOTH) . '|';
            $row .= str_pad(number_format($tradeInformation[BaseConstants::TRADING_CAPITAL], self::DECIMAL_PLACES_AMOUNT), $columnWidths[9], ' ', STR_PAD_LEFT) . '|';
            $row .= str_pad($tradeInformation[BaseConstants::TRADE_POSITION], $columnWidths[10], ' ', STR_PAD_BOTH) . '|';
            $row .= str_pad(number_format($tradeInformation[BaseConstants::TRADE_SPREAD], self::DECIMAL_PLACES_AMOUNT), $columnWidths[11], ' ', STR_PAD_LEFT) . '|';


            $this->output->writeln($row);
        }
    }

    private function calculateWinPercentage(array $tradesInformation) : float
    {
        $winAmount = 0;
        foreach ($tradesInformation as $tradeInformation) {
            if($tradeInformation[BaseConstants::IS_WINNER])
                $winAmount++;
        }

        return  $winAmount / count($tradesInformation);
    }

    private function calculateAverageFeeTax(array $tradesInformation) : float
    {
        $feeSum = 0;
        foreach ($tradesInformation as $tradeInformation) {
            $feeSum += $tradeInformation[BaseConstants::TRADE_FEE];
        }

        return  $feeSum / count($tradesInformation);
    }

    private function calculateAverageRiskRewardRatio(array $tradesInformation) : float
    {
        $riskRewardSum = 0;
        $countOfValidRiskReward = 0;
        foreach ($tradesInformation as $tradeInformation) {
            if($tradeInformation[BaseConstants::TRADE_RISK_REWARD])
            {
                $riskRewardSum += $tradeInformation[BaseConstants::TRADE_RISK_REWARD];
                $countOfValidRiskReward++;
            }
        }

        return  $riskRewardSum / $countOfValidRiskReward;
    }
}