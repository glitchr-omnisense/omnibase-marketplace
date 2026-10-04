<?php

namespace Base\Marketplace\Console;

use Base\Marketplace\Wishlist\Reservations;
use Base\Marketplace\Wishlist\Wishlists;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * marketplace:wishlist:refresh-prices: every night from the cron container.
 * Reads again the prices of the open lists' objects that are older than
 * marketplace.wishlist.price_ttl, and lets the lapsed reservations go.
 */
#[AsCommand(name: 'marketplace:wishlist:refresh-prices', description: 'Read the lists\' prices again, expire the reservations that lapsed.')]
class WishlistRefreshCommand extends Command
{
    public function __construct(private readonly Wishlists $wishlists, private readonly Reservations $reservations)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Prices read per run at most', '200');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $expired = $this->reservations->expire();
        $read = $this->wishlists->refreshPrices(null, (int) $input->getOption('limit'));
        (new SymfonyStyle($input, $output))->success(sprintf('%d price(s) read, %d reservation(s) lapsed.', $read, $expired));

        return Command::SUCCESS;
    }
}
