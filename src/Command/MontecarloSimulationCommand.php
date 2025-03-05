<?php

namespace App\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Dotenv\Dotenv;
use App\Service\MontecarloSimulationService;


class MontecarloSimulationCommand extends Command
{
    protected static $defaultName = 'app:montecarlo-simulation';

    private $montecarloSimulationService;

    public function __construct(MontecarloSimulationService $montecarloSimulationService)
    {
        parent::__construct();
        $this->montecarloSimulationService = $montecarloSimulationService;
    }

    protected function configure()
    {
        $this
            ->setDescription('This command will do a bunch of simulations in order to see if the specific strategy is effective or not');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {   
        $dotenv = new Dotenv();
        $dotenv->load(dirname(__DIR__, 2) . '/.env');

        //At the end long not trading gap because your capital is almost 0
        $simulationAmount = 1;
        // // $startDate = '2024-01-01';
        // $startDate = '2021-12-01';

        $startDate = '2022-06-21';
        $endDate =  '2024-10-21';   
        $initialTradingCapital = 1000;   

        // $simulationAmount = 1;
        // $startDate = '2024-01-01';
        // $startDate = '2019-01-01';
        // $endDate =  '2024-10-01';   

        $this
        ->montecarloSimulationService
        ->runMontecarloSimulation($output, 
                                    $startDate, 
                                    $endDate, 
                                    $simulationAmount, 
                                    $initialTradingCapital);


        return Command::SUCCESS;
    }

}

