<?php

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Doctrine\ORM\EntityManagerInterface;
use App\Service\YahooWebScrapService;
use App\Service\Nasdaq2000IndexService;
use App\Entity\Security;
use App\Entity\CandleStick;
use App\Interface\SwingTradingStrategyInterface;
use Symfony\Component\Console\Input\InputOption;
use DateTime;

#[AsCommand(
    name: 'app:check-exit-position',
    description: 'This cron will find whether you should stay or exit on your trade depending on what strategy you selected on services.yaml',
)]
class CheckExitPositionCommand extends Command
{
    private const AMOUNT_OF_NEXT_CANDLESTICKS = 100;


    private YahooWebScrapService $yahooWebScrapService;
    private EntityManagerInterface $entityManager;
    private SwingTradingStrategyInterface $swingTradingStrategy;
    private Nasdaq2000IndexService $nasdaq2000IndexService;
    
    public function __construct(YahooWebScrapService $yahooWebScrapService,
                                EntityManagerInterface $entityManager,
                                SwingTradingStrategyInterface $swingTradingStrategy,
                                Nasdaq2000IndexService $nasdaq2000IndexService
                                )
    {
        parent::__construct();
        $this->yahooWebScrapService = $yahooWebScrapService;
        $this->entityManager = $entityManager;
        $this->swingTradingStrategy = $swingTradingStrategy;
        $this->nasdaq2000IndexService = $nasdaq2000IndexService;
    }

    protected function configure(): void
    {
        $this
            ->setDescription('A custom cron command with parameters')
            ->addOption('ticker', null, InputOption::VALUE_REQUIRED, 'The ticker of the trade')
            ->addOption('date', null, InputOption::VALUE_REQUIRED, 'The trading date, when the position was executed')
            ->addOption('sharesAmount', null, InputOption::VALUE_REQUIRED, 'Shares amount of the trade')
            ->addOption('stopLoss', null, InputOption::VALUE_REQUIRED, 'Stop loss value of the trade');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $date = $input->getOption('date');
        $ticker = $input->getOption('ticker');
        $sharesAmount = $input->getOption('sharesAmount');
        $stopLoss = (float)$input->getOption('stopLoss');

        if (!$date || !$ticker || !$sharesAmount || !$stopLoss) {
            $output->writeln('<error>Both --date and --ticker options are required.</error>');
            return Command::FAILURE;
        }

        $output->writeln(sprintf("Updating nasdaq index..."));
        $this->nasdaq2000IndexService->updateNasdaqData();

        if($this->checkWhetherToExit($ticker, $date, $stopLoss, $sharesAmount, $output))
        {
            $output->writeln('You should exit this position.');
        }
        else 
        {
            $output->writeln('You should not exit this position.');
        }

        return Command::SUCCESS;
    }

    private function checkWhetherToExit($ticker, $tradingDate, $stopLoss, $sharesAmount,  OutputInterface $output) : bool 
    {
        $tradingDate = new DateTime($tradingDate);
        $security = $this->entityManager
        ->getRepository(Security::class)
        ->findOneBy(['ticker' => strtoupper($ticker)]);


        $currentYear = (int)(new DateTime())->format('Y');
        /** @var Security $security */
        $forex = $security->getIsForex();
        $cryptos = $security->getIsCrypto();

        if(!$forex)
            $forex = false;
        if($cryptos)
            $cryptos = false;

        $data = $this->yahooWebScrapService->getStockDataByDatesByOlderDates($ticker, 
        $currentYear, 
        $cryptos, $forex);

        $data = $this->yahooWebScrapService->getStockDataByDatesByOlderDates($ticker, 
        $currentYear, 
        $cryptos, $forex);

        if(!$data['Open Price'][0])
        {
            $output->writeln(sprintf("Something is wrong with %s", $ticker));
            return false;
        }

        for ($i=0; $i < count($data['Open Price']); $i++) { 
            $date = new DateTime(($data['Dates'][$i]));

            if($security->isDateExist($date) || !$data['Open Price'][$i])
                continue;
            
            $candleStick = new CandleStick();
            $candleStick->setVolume($data['Volume'][$i]);
            $candleStick->setOpenPrice($data['Open Price'][$i]);
            $candleStick->setHighestPrice($data['High Price'][$i]);
            $candleStick->setLowestPrice($data['Low Price'][$i]);
            $candleStick->setClosePrice($data['Close Price'][$i]);
            $candleStick->setDate($date);
            $security->addCandleStick($candleStick);
        }

        $nextCandleSticks = $security->getNextNCandleSticks($tradingDate, self::AMOUNT_OF_NEXT_CANDLESTICKS);

        $enterPrice = $nextCandleSticks[0]->getClosePrice();

        return $this->swingTradingStrategy->shouldIExit($security, $stopLoss, (float)$sharesAmount, $tradingDate, $enterPrice, $nextCandleSticks);
    }

}
