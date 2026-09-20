<?php

namespace Base\Market\Shopify\Console;

use Base\Market\Shopify\Api\AdminApi;
use Base\Market\Shopify\Api\ShopifyApiException;
use Base\Market\Shopify\Catalogue\ProductMapper;
use Base\Market\Shopify\Catalogue\ProductSynchronizer;
use Base\Market\Shopify\Catalogue\Query;
use Base\Market\Shopify\Repository\ProductLinkRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Pulls the Shopify catalogue into the shop.
 *
 * --dry-run first, always: it reads, reports what would change, and writes
 * nothing. The nightly job is the plain form; --since narrows it to what
 * moved, and the webhooks do the rest in between.
 *
 * Cursor pagination, 50 products a page. That is deliberately short of the
 * permitted 250: this query is costed on the nodes it COULD return (products
 * x variants), and 250 x 100 asks for more bucket than most shops have. Tune
 * it against the real extensions.cost figures rather than by guessing.
 *
 * Above a few thousand products the right answer is bulkOperationRunQuery and
 * a streamed JSONL result. That is not built here: it is asynchronous and
 * markedly harder to debug, and it would be a flag on this command rather
 * than a change to it.
 */
#[AsCommand(name: 'market:shopify:catalogue:sync', description: 'Import products and stock from Shopify')]
class SyncCatalogueCommand extends Command
{
    private const PAGE = 50;

    public function __construct(
        private readonly AdminApi $api,
        private readonly ProductMapper $mapper,
        private readonly ProductSynchronizer $synchronizer,
        private readonly ProductLinkRepository $links,
        #[Autowire('%market.shopify.catalogue.enabled%')] private readonly bool $enabled = false,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Report what would change; write nothing')
            ->addOption('full', null, InputOption::VALUE_NONE, 'Ignore the watermark and walk the whole catalogue')
            ->addOption('since', null, InputOption::VALUE_REQUIRED, 'Only products updated after this ISO-8601 instant')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Stop after this many variants');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if (!$this->enabled) {
            $io->warning('market.shopify.catalogue.enabled is false: nothing to do.');

            return Command::SUCCESS;
        }

        if (!$this->api->isConfigured()) {
            $io->error('Shopify is not configured. Run market:shopify:ping.');

            return Command::FAILURE;
        }

        $dryRun = (bool) $input->getOption('dry-run');
        $limit = $input->getOption('limit') ? (int) $input->getOption('limit') : null;
        $filter = $this->filter($input);

        $io->writeln(sprintf(
            'Syncing from <info>%s</info>%s%s',
            $this->api->endpoint()->shopDomain,
            $filter ? ' where ' . $filter : ' (everything)',
            $dryRun ? ' <comment>[dry run]</comment>' : '',
        ));

        $this->synchronizer->resetStats();
        $currency = $this->synchronizer->currency();
        $cursor = null;
        $seen = 0;
        $rows = [];

        try {
            do {
                $data = $this->api->query(Query::PRODUCTS, array_filter([
                    'first' => self::PAGE,
                    'after' => $cursor,
                    'query' => $filter,
                ], static fn ($v) => null !== $v));

                $page = $data['products'] ?? [];
                foreach ($page['nodes'] ?? [] as $node) {
                    foreach ($this->mapper->fromGraphQLNode($node, $currency) as $product) {
                        $result = $this->synchronizer->synchronize($product, $dryRun);
                        ++$seen;

                        if ('unchanged' !== $result) {
                            $rows[] = [$result, $product->handle ?? '', $product->variantTitle ?? '-', number_format($product->unitPrice / 100, 2), $product->stock ?? '~'];
                        }

                        if (null !== $limit && $seen >= $limit) {
                            break 3;
                        }
                    }
                }

                // Bounded memory on a big catalogue: write and let go.
                if (!$dryRun) {
                    $this->synchronizer->flush();
                }

                $cursor = ($page['pageInfo']['hasNextPage'] ?? false) ? ($page['pageInfo']['endCursor'] ?? null) : null;
            } while (null !== $cursor);
        } catch (ShopifyApiException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        if (!$dryRun) {
            $this->synchronizer->flush();
        }

        if ($rows) {
            $io->table(['', 'handle', 'variant', 'price', 'stock'], $rows);
        }

        $stats = $this->synchronizer->stats();
        $io->success(sprintf(
            '%d variants seen: %d created, %d updated, %d unchanged, %d skipped.%s',
            $seen, $stats['created'], $stats['updated'], $stats['unchanged'], $stats['skipped'],
            $dryRun ? ' Nothing was written.' : '',
        ));

        return Command::SUCCESS;
    }

    /** Shopify's search syntax for "only what moved since". */
    private function filter(InputInterface $input): ?string
    {
        if ($input->getOption('full')) {
            return null;
        }

        $since = $input->getOption('since');
        if (!$since) {
            $watermark = $this->links->lastSyncedAt($this->api->endpoint()->shop());
            $since = $watermark?->format(\DATE_ATOM);
        }

        if (!$since) {
            return null;
        }

        try {
            $when = new \DateTime((string) $since);
        } catch (\Exception) {
            return null;
        }

        return sprintf("updated_at:>'%s'", $when->format('Y-m-d\TH:i:s\Z'));
    }
}
