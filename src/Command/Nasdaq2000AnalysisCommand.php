<?php

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use App\Service\Nasdaq2000IndexService;

#[AsCommand(
    name: 'app:nasdaq-2000-analysis',
    description: "This common will analyse the specific nasdaq 2000 index performance it will update it's data and track for other intricacies",
)]
class Nasdaq2000AnalysisCommand extends Command
{
    private Nasdaq2000IndexService $nasdaq2000IndexService;

    public function __construct(Nasdaq2000IndexService $nasdaq2000IndexService) {
        parent::__construct();
        $this->nasdaq2000IndexService = $nasdaq2000IndexService;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $topN = 30;
        $bearish = false;
        $interval = 'year';

        $this->nasdaq2000IndexService->findMostBearishBullishDatesIntervalInHistory($topN, $interval, $bearish);

        return Command::SUCCESS;
    }
}