<?php

namespace App\Command;

use App\DataFixtures\ShopifyFixtures;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Loads the Shopify demo fixtures. Safe to run repeatedly.
 */
#[AsCommand(name: 'demo:shopify:seed', description: 'Seed the store, the payment method and a member for the Shopify demo')]
class ShopifyDemoSeedCommand extends Command
{
    public function __construct(private readonly ShopifyFixtures $fixtures)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('store', null, InputOption::VALUE_REQUIRED, 'The store slug to seed', 'boutique');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        foreach ($this->fixtures->load((string) $input->getOption('store')) as $line) {
            $io->writeln('  '.$line);
        }

        $io->success('Seeded. Next: bin/console market:shopify:ping');

        return Command::SUCCESS;
    }
}
