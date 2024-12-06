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
use DateTime;

#[AsCommand(
    name: 'app:find-trades',
    description: 'This cron will find high probability setups and will present them for you.',
)]
class FindTradesCommand extends Command
{
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

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $currentYear = (int)(new DateTime())->format('Y');

        $currentMonth = date('n'); // 'n' gives the numeric month without leading zeros (1-12)
        if ($currentMonth < 10) { // 10 represents October
            $currentYear--;
        } 

        $this->findTrades($output, $currentYear);

        return Command::SUCCESS;
    }

    private function findTrades(OutputInterface $output, $currentYear)
    {
        $output->writeln(sprintf("Updating nasdaq index..."));
        $this->nasdaq2000IndexService->updateNasdaqData();
        $securities = $this->entityManager->getRepository(Security::class)->findAll();

        shuffle($securities);
        
        foreach ($securities as $security) {
            $ticker = $security->getTicker();

            if($security->getIsForex())
                continue;

            $output->writeln(sprintf('Processing: %s', $ticker));

            /** @var Security $security */
            $forex = $security->getIsForex();
            $cryptos = $security->getIsCrypto();

            if(!$forex)
                $forex = false;
            if(!$cryptos)
                $cryptos = false;

            $data = $this->yahooWebScrapService->getStockDataByDatesByOlderDates($ticker, 
            $currentYear, 
            $cryptos, $forex);

            if(!$data['Open Price'][0])
            {
                $output->writeln(sprintf("Something is wrong with %s", $ticker));
                continue;
            }


            /** right now I'm only adding those candlesticks to the security but i will not persist them in the database. I'm only need them for analysis */
            for ($i=0; $i < count($data['Volume']); $i++) { 
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

            $tradingDate = new DateTime();

            if($this->swingTradingStrategy->canITrade($security, $tradingDate))
            {
                $output->writeln(sprintf("%s is acceptable for today's trading. Read the description of strategy before trading.", $ticker));
            }
        }
    }
}
