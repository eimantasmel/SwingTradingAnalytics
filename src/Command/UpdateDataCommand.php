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
use DateTime;
use Symfony\Component\Dotenv\Dotenv;


#[AsCommand(
    name: 'app:update-data',
    description: 'This cron will  update data about stocks and cryptos in the database',
)]
class UpdateDataCommand extends Command
{
    private const OLDER_DATE_START = 2020;
    private const MIN_VOLUME = 300_000;


    private $stocksFilePath;
    private $cryptosFilePath;
    private $yahooWebScrapService;
    private $entityManager;
    private $forexFilePath;

    
    public function __construct(YahooWebScrapService $yahooWebScrapService,
                                EntityManagerInterface $entityManager)
    {
        parent::__construct();
        $this->yahooWebScrapService = $yahooWebScrapService;   
        $this->entityManager = $entityManager;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $dotenv = new Dotenv();
        $dotenv->load(dirname(__DIR__, 2).'/.env');

 
        $this->updateSecuritiesData($output);

        return Command::SUCCESS;    
    }

    public function updateSecuritiesData(OutputInterface $output)
    {
        $securities = $this->entityManager->getRepository(Security::class)->findAll();
        $index = 0;
        foreach ($securities as $security) {
            /** @var Security $security */
            $forex = $security->getIsForex();
            $cryptos = $security->getIsCrypto();

            $ticker = $security->getTicker();


            $output->writeln(sprintf('Processing: %s', $ticker));
            

            $data = $this->yahooWebScrapService->getStockDataByDatesByOlderDates($ticker, 
            self::OLDER_DATE_START, 
            $cryptos, $forex);

            $security = new Security();
            $security->setTicker($ticker);
            $security->setIsCrypto($cryptos);
            $security->setIsForex($forex);


            if(!$data['Open Price'][0])
            {
                $output->writeln(sprintf("Something is wrong with %s", $ticker));
                continue;
            }

            if($data['Volume'][0] < self::MIN_VOLUME && !$forex)
            {
                $output->writeln(sprintf("Too low volume of %s, the volume only: %s", $ticker, $data['Volume'][0]));
                continue;
            }

            for ($i=0; $i < count($data['Volume']); $i++) { 
                $candleStick = new CandleStick();
                $candleStick->setVolume($data['Volume'][$i]);
                $candleStick->setOpenPrice($data['Open Price'][$i]);
                $candleStick->setHighestPrice($data['High Price'][$i]);
                $candleStick->setLowestPrice($data['Low Price'][$i]);
                $candleStick->setClosePrice($data['Close Price'][$i]);
                $candleStick->setDate(new DateTime(($data['Dates'][$i])));

                $security->addCandleStick($candleStick);
                $this->entityManager->persist($candleStick);
            }

            $this->entityManager->persist($security);

            $index++;
            if($index % 5 == 0)
            {
                $output->writeln("Flushing to the database...");
                $this->entityManager->flush();
            }

            // sleep(3);

        }
        $this->entityManager->flush();

    }
}
