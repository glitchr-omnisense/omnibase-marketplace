<?php

namespace Base\Marketplace\Console;

use Base\Marketplace\Catalogue\InventorySynchronizer;
use Base\Marketplace\Catalogue\PlatformSynchronizer;
use Base\Marketplace\Repository\Catalogue\PlatformLinkRepository;
use Omnitrade\Model\Product as RemoteProduct;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * marketplace:catalogue:sync [gateway]: the catalogue of a platform read in
 * (marketplace.catalogue.source when no gateway is named). Incremental by
 * default - what changed there since the last change read -, --full reads
 * everything and takes off sale what the platform no longer has,
 * --inventory reads the stock levels only. Run it from the cron container.
 */
#[AsCommand(name: 'marketplace:catalogue:sync', description: 'Read the catalogue of a platform (an omnitrade gateway) into the shop.')]
class CatalogueSyncCommand extends Command
{
    public function __construct(
        private readonly PlatformSynchronizer $products,
        private readonly InventorySynchronizer $inventory,
        private readonly PlatformLinkRepository $links,
        #[Autowire('%marketplace.catalogue.source%')] private readonly ?string $source = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('gateway', InputArgument::OPTIONAL, 'The omnitrade gateway (stripe, shopify, woocommerce...); default: marketplace.catalogue.source')
            ->addOption('full', null, InputOption::VALUE_NONE, 'Read everything, and take off sale what the platform no longer has')
            ->addOption('since', null, InputOption::VALUE_REQUIRED, 'Only what changed since this date (default: the last change read)')
            ->addOption('query', null, InputOption::VALUE_REQUIRED, 'Only what the platform\'s search finds')
            ->addOption('inventory', null, InputOption::VALUE_NONE, 'The stock levels only')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Say what would change, write nothing');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $gateway = $input->getArgument('gateway') ?? $this->source;
        if (!$gateway) {
            $io->error('No gateway: name one, or set marketplace.catalogue.source.');

            return Command::INVALID;
        }
        $dryRun = (bool) $input->getOption('dry-run');

        if ($input->getOption('inventory')) {
            if (!$this->inventory->reads($gateway)) {
                $io->note(sprintf('"%s" counts no stock: the stock stays the shop\'s.', $gateway));

                return Command::SUCCESS;
            }
            $io->success(sprintf('%d stock level(s) read from "%s".', $this->inventory->sync($gateway, $dryRun), $gateway));

            return Command::SUCCESS;
        }

        $full = (bool) $input->getOption('full');
        $since = $input->getOption('since') ? new \DateTimeImmutable($input->getOption('since')) : ($full ? null : $this->links->lastRemoteUpdate($gateway));
        $io->title(sprintf('Catalogue of "%s"%s%s', $gateway, $since ? ' changed since '.$since->format(\DATE_ATOM) : '', $dryRun ? ' (dry run)' : ''));

        $stats = $this->products->sync($gateway, $since, $full, $dryRun, function (RemoteProduct $product, string $outcome) use ($io): void {
            if ($io->isVerbose() || 'unchanged' !== $outcome) {
                $io->writeln(sprintf('  %-12s %s  <comment>%s</comment>', $outcome, $product->title, $product->reference));
            }
        }, $input->getOption('query'));

        if (!$dryRun && $this->inventory->reads($gateway)) {
            $stats['stock'] += $this->inventory->sync($gateway);
        }

        $io->definitionList(...array_map(fn ($k, $v) => [$k => (string) $v], array_keys($stats), $stats));

        return Command::SUCCESS;
    }
}
