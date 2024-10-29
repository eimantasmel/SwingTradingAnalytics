<?php

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Doctrine\ORM\EntityManagerInterface;
use App\Service\YahooWebScrapService;
use App\Entity\Security;
use App\Entity\CandleStick;
use Symfony\Component\Dotenv\Dotenv;


#[AsCommand(
    name: 'app:fetch-data',
    description: 'This cron will fetch or update data about stocks and cryptos in the database',
)]
class FetchDataCommand extends Command
{
    private const OLDER_DATE_START = 2020;


    private $stocksFilePath;
    private $cryptosFilePath;
    private $yahooWebScrapService;
    private $entityManager;

    
    public function __construct(YahooWebScrapService $yahooWebScrapService,
                                EntityManagerInterface $entityManager)
    {
        parent::__construct();
        $this->yahooWebScrapService = $yahooWebScrapService;
        $this->stocksFilePath = dirname(__DIR__, 2) . '/data/stocks.txt';      
        $this->cryptosFilePath = dirname(__DIR__, 2) . '/data/cryptos.txt';      
        $this->entityManager = $entityManager;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $dotenv = new Dotenv();
        $dotenv->load(dirname(__DIR__, 2).'/.env');

        $stocksTickers = file($this->stocksFilePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);    
        $cryptosTicker = file($this->cryptosFilePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);    

        foreach ($stocksTickers as $ticker) {
            $testSecurity = $this->entityManager->getRepository(Security::class)->findOneBy(['ticker' => strtoupper($ticker)]);
            if($testSecurity != null)
            {
                $output->writeln(sprintf('%s stock already exist in the database', $ticker));
                continue;
            }

            
            $data = $this->yahooWebScrapService->getStockDataByDatesByOlderDates($ticker, 
            self::OLDER_DATE_START, 
            false);

            $security = new Security();
            $security->setTicker($ticker);
            $security->setIsCrypto(false);

            for ($i=0; $i < count($data['Volume']); $i++) { 
                $candleStick = new CandleStick();
                $candleStick->setVolume($data['Volume'][$i]);
                $candleStick->setOpenPrice($data['Open Price'][$i]);
                $candleStick->setHighestPrice($data['High Price'][$i]);
                $candleStick->setLowestPrice($data['Low Price'][$i]);
                $candleStick->setClosePrice($data['Close Price'][$i]);
                // $candleStick->setDate()

            }

            sleep(8);
        }

        return Command::SUCCESS;    
    }
}
