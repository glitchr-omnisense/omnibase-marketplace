<?php

namespace Base\Marketplace\Console;

use Base\Marketplace\Entity\Order\Method\PaymentMethod;
use Base\Marketplace\Payment\Omnitrade\OmnitradeGateway;
use Base\Marketplace\Payment\Omnitrade\OmnitradeGateways;
use Base\Marketplace\Payment\PaymentGatewayRegistry;
use Base\Service\SettingBagInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Makes sure Stripe knows where to send the marketplace's events: for every
 * payment method on an omnitrade gateway whose provider is Stripe, the
 * webhook endpoint on marketplace_payment_webhook, listening to the Checkout
 * events PaymentController handles. Safe to run again and again - on every
 * deploy, from the container's entrypoint:
 *
 *   - no API key yet, or an address Stripe cannot reach (localhost: use
 *     `stripe listen` there): nothing to do, said so, success;
 *   - the endpoint exists: its events are completed if some are missing;
 *   - it does not: it is created, and the signing secret Stripe returns -
 *     ONLY at creation - is stored in the back office's setting
 *     api.payment_method.<gateway>.webhook_secret, which the omnitrade
 *     gateway runs on from then on: every event's signature is checked
 *     against it. No secret to copy.
 *
 * The API key: the one the omnitrade gateway runs on - typed in the back
 * office (api.payment_method.<gateway>.api_key) or configured
 * (omnitrade.gateways.<gateway>.options.api_key, STRIPE_API_KEY). The
 * address: --url, else the method's `webhook_url` setting, else the route's
 * absolute URL under the router's default URI.
 *
 * An endpoint that exists but whose secret is known nowhere (created by hand,
 * or the settings lost) cannot be read back from the API: reveal it in the
 * Dashboard into webhook_secret, or run with --recreate for a new one.
 */
#[AsCommand(name: 'marketplace:stripe:webhook', description: "Create the Stripe webhook endpoint the marketplace listens on, if Stripe does not have it yet")]
final class StripeWebhookCommand extends Command
{
    public const EVENTS = [
        'checkout.session.completed',
        'checkout.session.async_payment_succeeded',
        'checkout.session.expired',
        'checkout.session.async_payment_failed',
    ];

    private const API = 'https://api.stripe.com/v1/webhook_endpoints';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UrlGeneratorInterface $urls,
        private readonly HttpClientInterface $http,
        private readonly SettingBagInterface $settings,
        private readonly PaymentGatewayRegistry $gateways,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('url', null, InputOption::VALUE_REQUIRED, 'The public address of the webhook (default: the gateway\'s webhook_url, else the route under the router\'s default URI)')
            ->addOption('recreate', null, InputOption::VALUE_NONE, 'Delete the endpoint at that address and create it again - for a new signing secret')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Say what would be done, change nothing');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dry = (bool) $input->getOption('dry-run');

        $methods = [];
        foreach ($this->entityManager->getRepository(PaymentMethod::class)->findAll() as $method) {
            $bridge = $this->gateways->get($method->getGatewayFactory());
            if ($bridge instanceof OmnitradeGateway && 'stripe' === $bridge->provider()) {
                $methods[] = [$method, $bridge];
            }
        }
        if (!$methods) {
            $io->note('No payment method on a Stripe gateway: nothing to do.');

            return Command::SUCCESS;
        }

        $status = Command::SUCCESS;
        foreach ($methods as [$method, $bridge]) {
            $slug = (string) $method->getSlug();
            $parameters = $method->getGatewayParameters();
            $key = (string) $bridge->option('api_key');
            if ('' === $key) {
                $io->note(sprintf('%s: no Stripe API key yet (typed in the back office, or STRIPE_API_KEY), nothing to do.', $slug));
                continue;
            }

            $url = (string) ($input->getOption('url') ?: ($parameters['webhook_url'] ?? '') ?: $this->urls->generate('marketplace_payment_webhook', ['gateway' => $bridge->getName()], UrlGeneratorInterface::ABSOLUTE_URL));
            if (!self::reachable($url)) {
                $io->note(sprintf('%s: Stripe cannot reach %s - locally, use `stripe listen`; elsewhere, set the gateway\'s webhook_url or pass --url.', $slug, $url));
                continue;
            }

            try {
                $status = max($status, $this->ensure($io, $method, $bridge, $key, $url, (bool) $input->getOption('recreate'), $dry));
            } catch (ExceptionInterface|\RuntimeException $e) {
                $io->error(sprintf('%s: Stripe did not answer: %s', $slug, $e->getMessage()));
                $status = Command::FAILURE;
            }
        }

        return $status;
    }

    private function ensure(SymfonyStyle $io, PaymentMethod $method, OmnitradeGateway $bridge, string $key, string $url, bool $recreate, bool $dry): int
    {
        $slug = (string) $method->getSlug();
        $mode = str_starts_with($key, 'sk_live_') || str_starts_with($key, 'rk_live_') ? 'live' : 'test';
        $found = null;
        foreach ($this->endpoints($key) as $endpoint) {
            if (($endpoint['url'] ?? null) === $url) {
                $found = $endpoint;
                break;
            }
        }

        if ($found && $recreate) {
            $io->text(sprintf('%s: deleting %s to create it again.', $slug, $found['id']));
            if (!$dry) {
                $this->call($key, 'DELETE', self::API.'/'.$found['id']);
            }
            $found = null;
        }

        if ($found) {
            $events = $found['enabled_events'] ?? [];
            $missing = in_array('*', $events, true) ? [] : array_values(array_diff(self::EVENTS, $events));
            if ($missing) {
                $io->text(sprintf('%s: %s listens to %s - adding %s.', $slug, $found['id'], $events ? implode(', ', $events) : 'nothing', implode(', ', $missing)));
                if (!$dry) {
                    $this->call($key, 'POST', self::API.'/'.$found['id'], ['enabled_events' => array_values(array_unique([...$events, ...$missing]))]);
                }
            }
            if ('disabled' === ($found['status'] ?? null)) {
                $io->warning(sprintf('%s: %s is disabled in Stripe - enable it in the Dashboard.', $slug, $found['id']));
            }
            $io->success(sprintf('%s: Stripe (%s mode) already sends the marketplace\'s events to %s (%s).', $slug, $mode, $url, $found['id']));
            if (null === $bridge->option('webhook_secret')) {
                $io->warning(sprintf('%s: its signing secret is known nowhere, and Stripe only gives it out at creation. Reveal it in the Dashboard (Developers > Webhooks) and type it in the back office (%s.%s.webhook_secret), or run again with --recreate.', $slug, OmnitradeGateways::SETTINGS, $bridge->getName()));

                return Command::FAILURE;
            }

            return Command::SUCCESS;
        }

        $io->text(sprintf('%s: creating the endpoint %s in %s mode.', $slug, $url, $mode));
        if ($dry) {
            return Command::SUCCESS;
        }

        $created = $this->call($key, 'POST', self::API, [
            'url' => $url,
            'enabled_events' => self::EVENTS,
            'description' => sprintf('Marketplace - %s (%s)', $method->getLabel() ?: $slug, $slug),
            'metadata' => ['created_by' => 'marketplace:stripe:webhook', 'payment_method' => $slug],
        ]);
        $secret = (string) ($created['secret'] ?? '');
        $setting = OmnitradeGateways::SETTINGS.'.'.$bridge->getName().'.webhook_secret';
        $this->settings->set($setting, $secret);
        $this->settings->secure($setting);

        $io->success(sprintf('%s: created %s; its signing secret (%s…) is stored in the setting %s, which the gateway checks events with.', $slug, $created['id'] ?? '?', substr($secret, 0, 10), $setting));

        return Command::SUCCESS;
    }

    /** @return iterable<array> every endpoint of the account, page by page */
    private function endpoints(string $key): iterable
    {
        $after = null;
        do {
            $page = $this->call($key, 'GET', self::API, null, ['limit' => 100] + ($after ? ['starting_after' => $after] : []));
            foreach ($page['data'] ?? [] as $endpoint) {
                yield $endpoint;
                $after = $endpoint['id'];
            }
        } while (($page['has_more'] ?? false) && $after);
    }

    private function call(string $key, string $verb, string $url, ?array $body = null, array $query = []): array
    {
        $response = $this->http->request($verb, $url, array_filter([
            'auth_bearer' => $key,
            'query' => $query,
            // Stripe reads form fields, lists as name[0]=...: what http_build_query writes.
            'body' => null === $body ? null : http_build_query($body),
            'headers' => null === $body ? [] : ['Content-Type' => 'application/x-www-form-urlencoded'],
        ], fn ($value) => null !== $value && [] !== $value));
        $data = $response->toArray(false);
        if ($response->getStatusCode() >= 400) {
            throw new \RuntimeException(sprintf('Stripe answered %d: %s', $response->getStatusCode(), $data['error']['message'] ?? 'no message'));
        }

        return $data;
    }

    /** Whether Stripe, on the internet, can post to $url. */
    private static function reachable(string $url): bool
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        // Plain http is accepted by Stripe in test mode only; live mode refuses it itself.
        if ('' === $host || !in_array(parse_url($url, PHP_URL_SCHEME), ['http', 'https'], true)) {
            return false;
        }
        // A single-label host is no public address: the router outside a request can make one up
        // ("https://https/..." when it has no default URI), and Stripe would be given it.
        if (!str_contains($host, '.') && !filter_var($host, FILTER_VALIDATE_IP)) {
            return false;
        }
        if (in_array($host, ['localhost', '127.0.0.1', '::1', '[::1]'], true) || preg_match('/\.(localhost|local|test|internal)$/', $host)) {
            return false;
        }
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return false !== filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
        }

        return true;
    }
}
