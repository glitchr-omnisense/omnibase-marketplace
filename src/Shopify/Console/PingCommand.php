<?php

namespace Base\Market\Shopify\Console;

use Base\Market\Shopify\Api\AdminApi;
use Base\Market\Shopify\Api\ShopifyApiException;
use Base\Market\Shopify\Catalogue\Query;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * One round trip, to prove the domain, the token and the API version are all
 * right before anything else is blamed. The first thing to run against a new
 * shop, and the first thing to run when something stops working.
 */
#[AsCommand(name: 'market:shopify:ping', description: 'Check the Shopify credentials with one API call')]
class PingCommand extends Command
{
    public function __construct(private readonly AdminApi $api)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $endpoint = $this->api->endpoint();

        if (!$this->api->isConfigured()) {
            $io->error('Shopify is not configured. Set market.shopify.shop_domain and market.shopify.admin_token.');
            $io->writeln(sprintf('  shop_domain: %s', $endpoint->shopDomain ?: '<empty>'));
            $io->writeln(sprintf('  admin_token: %s', '' !== $endpoint->adminToken ? '<set>' : '<empty>'));

            return Command::FAILURE;
        }

        $io->writeln(sprintf('Asking <info>%s</info> (API %s)...', $endpoint->shopDomain, $endpoint->apiVersion));

        try {
            $shop = ($this->api->query(Query::SHOP)['shop'] ?? []);
        } catch (ShopifyApiException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $io->success('Connected.');
        $io->definitionList(
            ['name' => (string) ($shop['name'] ?? '?')],
            ['domain' => (string) ($shop['myshopifyDomain'] ?? '?')],
            ['currency' => (string) ($shop['currencyCode'] ?? '?')],
            ['timezone' => (string) ($shop['ianaTimezone'] ?? '?')],
            ['plan' => (string) ($shop['plan']['displayName'] ?? '?')],
            ['webhooks' => $endpoint->canVerifyWebhooks() ? 'secret set' : 'NO SECRET - webhooks will be refused'],
        );

        return Command::SUCCESS;
    }
}
