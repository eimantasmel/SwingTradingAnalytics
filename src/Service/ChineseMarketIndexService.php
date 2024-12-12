<?php

namespace App\Service;

use DateTime;
use App\Constants\BaseConstants;
use Doctrine\ORM\EntityManagerInterface;
use App\Entity\Security;
use App\Entity\CandleStick;
use App\Service\YahooWebScrapService;


class ChineseMarketIndexService
{
    private EntityManagerInterface $entityManager;
    private Security $chineseIndexData;
    private YahooWebScrapService $yahooWebScrapService;

    public function __construct(EntityManagerInterface $entityManager,
                                YahooWebScrapService $yahooWebScrapService) {
        $this->entityManager = $entityManager;
        $this->yahooWebScrapService = $yahooWebScrapService;
        $this->chineseIndexData = $this->entityManager
        ->getRepository(Security::class)
        ->findOneBy(['ticker' => BaseConstants::CHINESE_MARKET_TICKER]);
    }

    public function updateChineseIndexData()
    {
        $lastCandleStick = $this->chineseIndexData->getLastCandleStick();
        $lastYear = $lastCandleStick->getDate()->format("Y");
        $date = new DateTime();

        if($lastCandleStick->getDate()->diff($date)->days == 0)
        {
            return ;
        }

        $data = $this
                    ->yahooWebScrapService
                    ->getStockDataByDatesByOlderDates(BaseConstants::NASDAQ_2000_TICKER, $lastYear);

 
        if(!$data['Open Price'][0])
        {
            echo "Something went wrong with retrieving nasdaq data \r\n";
            return null;
        }

        $index = 0;
        for ($i=0; $i < count($data['Volume']); $i++) { 
            $date = new DateTime(($data['Dates'][$i]));

            if($this->chineseIndexData->isDateExist($date) || !$data['Close Price'][$i])
                continue;

            $candleStick = new CandleStick();
            $candleStick->setVolume($data['Volume'][$i]);
            $candleStick->setOpenPrice($data['Open Price'][$i]);
            $candleStick->setHighestPrice($data['High Price'][$i]);
            $candleStick->setLowestPrice($data['Low Price'][$i]);
            $candleStick->setClosePrice($data['Close Price'][$i]);
            $candleStick->setDate($date);

            $this->chineseIndexData->addCandleStick($candleStick);
            $this->entityManager->persist($candleStick);

            if($index++ % 5 == 0)
            {
                $this->entityManager->flush();
            }
        }

        $this->entityManager->flush();
    }
}