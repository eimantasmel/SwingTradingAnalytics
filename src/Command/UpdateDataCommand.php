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
    private const OLDER_DATE_START = 2016;  // in order to update only prices to the most current it's better to choose the last year then it will bring data much quicker
    private const MIN_VOLUME = 300_000;

    

    private $yahooWebScrapService;
    private $entityManager;

    
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

        $earliestDate = $this->getEarliestDate($securities[0]);

        // Check if older date is newer than earliest date from the database then it will update till today's date
        // In other case it will update only till the earliest date from database in order to do not waste additional resources of calling yahoo finance service.
        if((int)$earliestDate->format('Y') < self::OLDER_DATE_START)
        {
            $earliestDate = null;
        }

        $index = 0;
        foreach ($securities as $security) {

            $olderDateYear = self::OLDER_DATE_START;
            /** Check does the candlestick with the older date exist */
            /** I need to check three dates because it might be a weekend and that check would be inconclusive in order to skip data which already exist in DB */
            $dateToCheck1 = new DateTime("{$olderDateYear}-01-01");
            $dateToCheck2 = new DateTime("{$olderDateYear}-01-02");
            $dateToCheck3 = new DateTime("{$olderDateYear}-01-03");

            if($earliestDate && 
                (
                    $security->isDateExist($dateToCheck1) ||
                    $security->isDateExist($dateToCheck2) ||
                    $security->isDateExist($dateToCheck3) 
                )
              )
                continue;
            /** @var Security $security */
            $forex = $security->getIsForex();
            $cryptos = $security->getIsCrypto();

            if(!$forex)
                $forex = false;
            if($cryptos)
                $cryptos = false;

            $ticker = $security->getTicker();


            $output->writeln(sprintf('Processing: %s', $ticker));
            

            $data = $this->yahooWebScrapService->getStockDataByDatesByOlderDates($ticker, 
            self::OLDER_DATE_START, 
            $cryptos, $forex, $earliestDate);

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
                $this->entityManager->persist($candleStick);
            }

            $this->entityManager->persist($security);

            $index++;
            if($index % 3 == 0)
            {
                $output->writeln("Flushing to the database...");
                $this->entityManager->flush();
            }

            // sleep(3);

        }
        $this->entityManager->flush();

    }

    private function getEarliestDate(Security $security)
    {
        $firstCandleStick = $security->getFirstCandleStick();
        $date = $firstCandleStick->getDate();

        return $date;
    }
}
