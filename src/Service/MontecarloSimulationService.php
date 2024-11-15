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
            $j = 0;                                                
            while($j < self::EXHAUSTION_AMOUNT)
            {
                $data = $this->swingTradingStrategy->getSimulationData($startDate,
                                                $endDate,
                                                $initialTradingCapital,
                                                $securities);
                if($data[BaseConstants::AMOUNT_OF_TRADES] >= self::MIN_AMOUNT_OF_TRADES
                    && !in_array($data[BaseConstants::FINAL_TRADING_CAPITAL], $this->combinations))
                {
                    $results[BaseConstants::FINAL_TRADING_CAPITAL][] = $data[BaseConstants::FINAL_TRADING_CAPITAL];
                    $results[BaseConstants::AMOUNT_OF_TRADES] += $data[BaseConstants::AMOUNT_OF_TRADES];
                    $results[BaseConstants::TRADES_INFORMATION][] = $data[BaseConstants::TRADES_INFORMATION];
                    $this->combinations[] = $data[BaseConstants::FINAL_TRADING_CAPITAL];
                    break;
                }
                $j++;
                if($j == self::EXHAUSTION_AMOUNT)
                {
                    $this->output->writeln('Unfortunately simulation ended due to the insufficient amount of combinations');
                    break 2;
                }
            }
        }

        echo "Total amount of trades is: " . $results[BaseConstants::AMOUNT_OF_TRADES] . "\r\n";
        echo "Final trading capital is:" . $results[BaseConstants::FINAL_TRADING_CAPITAL][0];

        $this->printTradesInformationData($results[BaseConstants::TRADES_INFORMATION][0]);

        return $results;
    }

    //TODO: tomorow ask chat gpt to makes this more like a table with headers and proper column alignments.
    private function printTradesInformationData(array $tradesInformation) 
    {
        $this->output->writeln('');
        $this->output->writeln('');

        // Define column headers and widths
        $headers = ['#', 'Trade Date', 'Exit Date', 'Ticker', 'Enter Price', 'Stop Loss', 'Take Profit', 'Exit Price', 'Winner', 'Capital'];
        $columnWidths = [5, 12, 12, 10, 12, 12, 12, 12, 8, 12];
    
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
            
            $this->output->writeln($row);
        }
    }


    // when you will done then run again your simulation because results still too big.
    // maybe it just got lucky i dunno. 
    // private function printTradesInformationData(array $tradesInformation)
    // {
    //     $counter = 0;
    //     foreach($tradesInformation as $tradeInforation)
    //     {
    //         $this->output->writeln(sprintf("|%s|%s|%s|%s|%s|%s|%s|%s|%s|%s|"),
    //                                 ++$counter,
    //                                 $tradeInforation[BaseConstants::TRADE_DATE],
    //                                 $tradeInforation[BaseConstants::EXIT_DATE],
    //                                 $tradeInforation[BaseConstants::TRADE_SECURITY_TICKER],
    //                                 $tradeInforation[BaseConstants::TRADE_ENTER_PRICE],
    //                                 $tradeInforation[BaseConstants::TRADE_STOP_LOSS_PRICE],
    //                                 $tradeInforation[BaseConstants::TRADE_TAKE_PROFIT_PRICE],
    //                                 $tradeInforation[BaseConstants::TRADE_EXIT_PRICE],
    //                                 $tradeInforation[BaseConstants::IS_WINNER],
    //                                 $tradeInforation[BaseConstants::TRADING_CAPITAL]
    //                             );           
    //     }
    // }
}