<?php

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use App\Interface\MarketIndexInterface;

#[AsCommand(
    name: 'app:market-index-analysis',
    description: "This common will analyse the specific market index performance it will update it's data and track for other intricacies",
)]
class MarketAnalysisCommand extends Command
{
    private MarketIndexInterface $marketIndex;

    public function __construct(MarketIndexInterface $marketIndex) {
        parent::__construct();
        $this->marketIndex = $marketIndex;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $topN = 10;
        $bearish = true;
        $interval = 'month';

        // $this->marketIndex->findMostBearishBullishDayDatesInHistory($topN, $bearish);
        $this->marketIndex->findMostBearishBullishDatesIntervalInHistory($topN, $interval, $bearish);


        return Command::SUCCESS;
    }
}