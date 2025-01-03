<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Doctrine\ORM\EntityManagerInterface;
use App\Entity\Security;
use App\Entity\CandleStick;
use App\Interface\MarketIndexInterface;


class ChartController extends AbstractController
{
    private $entityManager;
    private $marketIndexInterface;
    
    public function __construct(EntityManagerInterface $entityManager,
                                MarketIndexInterface $marketIndexInterface,
                               )
    {
        $this->entityManager = $entityManager;
        $this->marketIndexInterface = $marketIndexInterface;
    }


    #[Route('/candlestick-chart/{ticker}', name: 'candlestick_chart', defaults: ['ticker' => 'default'], requirements: ['ticker' => '\w+'])]
    public function index(string $ticker): Response
    {
        if($ticker == 'default')
            $ticker = $this->marketIndexInterface->getTicker();
        
        $security = $this->entityManager->getRepository(Security::class)->findOneBy(['ticker' => $ticker]);

        if (!$security) {
            throw $this->createNotFoundException(sprintf('No security found for ticker "%s".', $ticker));
        }

        /** @var Security $security */

        $candleSticks = $security->getCandleSticks();

        // Convert CandleStick entities to an array in the required format
        $candlestickData = [];
        foreach ($candleSticks as $candleStick) {
            /** @var CandleStick $candleStick */
            $candlestickData[] = [
                'date' => $candleStick->getDate()->format('Y-m-d'),
                'open' => $candleStick->getOpenPrice(),
                'high' => $candleStick->getHighestPrice(),
                'low' => $candleStick->getLowestPrice(),
                'close' => $candleStick->getClosePrice(),
            ];
        }

        // Pass the data to the template (you can modify it to fit your needs)
        return $this->render('chart.html.twig', [
            'candlestickData' => $candlestickData,
            'ticker' => strtoupper($ticker)
        ]);
    }
}
