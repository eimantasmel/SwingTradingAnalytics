<?php

namespace App\Constants;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Dotenv\Dotenv;
use App\Service\MontecarloSimulationService;

class BaseConstants 
{
    public const AMOUNT_OF_TRADES = 'Amount of Trades';
    public const FINAL_TRADING_CAPITAL = 'Final Trading Capital';

    public const TRADES_INFORMATION = 'Trades Information';
    public const TRADE_DATE = 'Trade Date';
    public const TRADE_SECURITY_TICKER = 'Trade Security Ticker';
    public const EXIT_DATE = 'Exit Date';
    public const TRADE_ENTER_PRICE = 'Trade Enter Price';
    public const TRADE_STOP_LOSS_PRICE = 'Trade Stop Loss Price';
    public const TRADE_TAKE_PROFIT_PRICE = 'Trade Stop Loss Price';
    public const TRADE_EXIT_PRICE = 'Trade Exit Price';
    public const IS_WINNER = 'Is Winner';
    public const TRADING_CAPITAL = 'Trading Capital';

}