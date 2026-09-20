<?php

namespace Base\Market\Shopify\Console;

use Base\Market\Shopify\Api\AdminApi;
use Base\Market\Shopify\Api\ShopifyApiException;
use Base\Market\Shopify\Catalogue\Query;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Reconciles the shop's webhook subscriptions with the ones this integration
 * wants.
 *
 * Reconciles rather than creates: run it twice and the second run does
 * nothing. Shopify refuses a duplicate topic+URL anyway, but reporting "already
 * there" beats reporting an error that is not one.
 *
 * --prune removes subscriptions pointing at THIS application's callback URL
 * for topics no longer wanted. It never touches a subscription belonging to
 * another app: matching is on the callback URL, not the topic.
 */
#[AsCommand(name: 'market:shopify:webhooks', description: 'List, install or prune the Shopify webhook subscriptions')]
class WebhooksCommand extends Command
{
    /** The topics this integration knows how to handle. */
    public const TOPICS = [
        'ORDERS_PAID' => 'orders/paid',
        'ORDERS_CANCELLED' => 'orders/cancelled',
        'PRODUCTS_CREATE' => 'products/create',
        'PRODUCTS_UPDATE' => 'products/update',
        'PRODUCTS_DELETE' => 'products/delete',
        'INVENTORY_LEVELS_UPDATE' => 'inventory_levels/update',
        'FULFILLMENTS_CREATE' => 'fulfillments/create',
    ];

    public function __construct(
        private readonly AdminApi $api,
        private readonly UrlGeneratorInterface $urls,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('install', null, InputOption::VALUE_NONE, 'Create the subscriptions that are missing')
            ->addOption('prune', null, InputOption::VALUE_NONE, 'Delete our subscriptions for topics no longer wanted')
            ->addOption('callback', null, InputOption::VALUE_REQUIRED, 'Override the callback URL (a tunnel, in development)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if (!$this->api->isConfigured()) {
            $io->error('Shopify is not configured. Run market:shopify:ping.');

            return Command::FAILURE;
        }

        $callback = (string) ($input->getOption('callback')
            ?: $this->urls->generate('market_shopify_webhook', [], UrlGeneratorInterface::ABSOLUTE_URL));

        if (!str_starts_with($callback, 'https://')) {
            $io->warning(sprintf('Shopify only calls https endpoints; this is %s. Pass --callback with a tunnel URL in development.', $callback));
        }

        try {
            $existing = [];
            foreach (($this->api->query(Query::WEBHOOK_SUBSCRIPTIONS, ['first' => 100])['webhookSubscriptions']['nodes'] ?? []) as $node) {
                $existing[] = [
                    'id' => $node['id'] ?? '',
                    'topic' => $node['topic'] ?? '',
                    'url' => $node['endpoint']['callbackUrl'] ?? '',
                ];
            }

            $io->section('Currently subscribed');
            $io->table(['topic', 'callback'], array_map(static fn ($s) => [$s['topic'], $s['url']], $existing) ?: [['(none)', '']]);

            if ($input->getOption('install')) {
                $io->section('Installing');
                foreach (self::TOPICS as $enum => $label) {
                    $already = array_filter($existing, static fn ($s) => $s['topic'] === $enum && $s['url'] === $callback);
                    if ($already) {
                        $io->writeln(sprintf('  <comment>=</comment> %s already there', $label));
                        continue;
                    }

                    $this->api->mutate(Query::WEBHOOK_CREATE, ['topic' => $enum, 'callbackUrl' => $callback], 'webhookSubscriptionCreate');
                    $io->writeln(sprintf('  <info>+</info> %s', $label));
                }
            }

            if ($input->getOption('prune')) {
                $io->section('Pruning');
                foreach ($existing as $subscription) {
                    // Only ever ours: same callback URL, topic we dropped.
                    if ($subscription['url'] !== $callback || isset(self::TOPICS[$subscription['topic']])) {
                        continue;
                    }

                    $this->api->mutate(Query::WEBHOOK_DELETE, ['id' => $subscription['id']], 'webhookSubscriptionDelete');
                    $io->writeln(sprintf('  <info>-</info> %s', $subscription['topic']));
                }
            }
        } catch (ShopifyApiException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        if (!$input->getOption('install') && !$input->getOption('prune')) {
            $io->note(sprintf('Nothing changed. Pass --install to subscribe to the %d topics this integration handles.', \count(self::TOPICS)));
        }

        return Command::SUCCESS;
    }
}
