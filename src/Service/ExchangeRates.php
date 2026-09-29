<?php

namespace Base\Market\Service;

use Base\Market\Entity\Product;
use Base\Market\Entity\Sales\Forex;
use Base\Market\Entity\Sales\Region;
use Base\Market\Entity\Store;
use Base\Service\SettingBagInterface;
use Base\Service\TradingInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * The exchange rates the shop converts with: the Forex table ("Taux de
 * change" in the back office), one row per pair from the store's currency.
 *
 * At the start of every request and command they are handed to base-bundle's
 * Trading as its rates (addFallback): Order and OrderItem convert, the
 * format_currency filter displays, and none of them asks a provider anything
 * while a visitor's page is built - a paid API's monthly quota is not spent
 * by visitors, and a slow provider slows no page.
 *
 * refresh() asks for the day's rates and writes them in the table - on
 * demand only, an administrator's action or a command. One HTTP call for
 * every currency at once: Fixer.io when its key is in the settings
 * (market.forex.fixer; the free plan counts 100 calls a month), else the
 * European Central Bank's reference rates, free and keyless. A rate set by
 * hand stays until the next refresh.
 */
final class ExchangeRates implements ResetInterface
{
    public const FIXER_KEY = 'market.forex.fixer';

    // Fixer's free plan: plain http, from the euro only.
    private const FIXER_URL = 'http://data.fixer.io/api/latest';
    private const ECB_URL = 'https://www.ecb.europa.eu/stats/eurofxref/eurofxref-daily.xml';
    // Someone waits on the answer: not for ever.
    private const TIMEOUT = 10;

    private bool $loaded = false;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly TradingInterface $trading,
        private readonly HttpClientInterface $http,
        #[Autowire('%market.default_currency%')]
        private readonly string $currency,
        private readonly ?SettingBagInterface $settings = null,
    ) {
    }

    /** The table, as Trading's rates: once per request or command. */
    #[AsEventListener(KernelEvents::REQUEST, priority: 400)]
    #[AsEventListener(ConsoleEvents::COMMAND)]
    public function load(): void
    {
        if ($this->loaded) {
            return;
        }
        $this->loaded = true;

        try {
            $rates = $this->entityManager->getRepository(Forex::class)->findAll();
        } catch (\Throwable) {
            return; // no table yet (before the migration), no database
        }
        foreach ($rates as $forex) {
            if ($forex->getRate() > 0) {
                $this->trading->addFallback($forex->getSource(), $forex->getTarget(), $forex->getRate(), $forex->getUpdatedAt()?->format('Y-m-d H:i:s') ?? 'now');
            }
        }
    }

    /** A worker serves many requests: each reads the table again. */
    public function reset(): void
    {
        $this->loaded = false;
    }

    /** @return Forex[] the rates from the store's currency, by target */
    public function rates(): array
    {
        return $this->entityManager->getRepository(Forex::class)->findBy(['source' => $this->currency], ['target' => 'ASC']);
    }

    /** The rate from the store's currency to $target, from the table; null when there is none. */
    public function rate(string $target): ?float
    {
        if ($target === $this->currency) {
            return 1.0;
        }
        $forex = $this->entityManager->getRepository(Forex::class)->findOneBy(['source' => $this->currency, 'target' => $target]);

        return $forex?->getRate() ?: null;
    }

    /**
     * Asks the provider for the day's rates - one HTTP call - and writes them,
     * from the store's currency to every currency the shop uses (its stores,
     * regions and products) and to the $targets given.
     *
     * @param string[] $targets
     *
     * @return Forex[] the rates written
     *
     * @throws \RuntimeException when the provider does not answer with rates
     */
    public function refresh(array $targets = []): array
    {
        $key = trim((string) $this->settings?->getScalar(self::FIXER_KEY));
        [$provider, $fromEuro] = '' !== $key ? $this->fromFixer($key) : $this->fromEcb();
        $fromEuro['EUR'] = 1.0;
        if (empty($fromEuro[$this->currency])) {
            throw new \RuntimeException(sprintf('%s: no rate for %s, the store\'s currency.', $provider, $this->currency));
        }

        $targets = array_values(array_unique(array_filter(
            array_map('strtoupper', [...$targets, ...$this->currenciesInUse()]),
            fn (string $target) => $target !== $this->currency && !empty($fromEuro[$target]),
        )));
        sort($targets);

        $repository = $this->entityManager->getRepository(Forex::class);
        $written = [];
        $this->entityManager->wrapInTransaction(function () use ($repository, $targets, $fromEuro, $provider, &$written) {
            foreach ($targets as $target) {
                $forex = $repository->findOneBy(['source' => $this->currency, 'target' => $target])
                    ?? (new Forex())->setSource($this->currency)->setTarget($target);
                // Both rates are from the euro: the pair's is their ratio.
                $forex->setRate($fromEuro[$target] / $fromEuro[$this->currency], $provider);
                $this->entityManager->persist($forex);
                $written[] = $forex;
            }
        });

        $this->reset();
        $this->load();

        return $written;
    }

    /** @return string[] the currencies of the shop's stores, regions and products */
    private function currenciesInUse(): array
    {
        $currencies = [];
        foreach ([Store::class, Region::class, Product::class] as $class) {
            try {
                $found = $this->entityManager->createQuery(sprintf('SELECT DISTINCT e.currency FROM %s e WHERE e.currency IS NOT NULL', $class))->getSingleColumnResult();
            } catch (\Throwable) {
                continue;
            }
            array_push($currencies, ...$found);
        }

        return $currencies;
    }

    /** @return array{string, array<string, float>} the provider's name, and its rates from the euro */
    private function fromFixer(string $key): array
    {
        $answer = $this->http->request('GET', self::FIXER_URL, ['query' => ['access_key' => $key], 'timeout' => self::TIMEOUT])->toArray(false);
        if (!($answer['success'] ?? false) || empty($answer['rates'])) {
            throw new \RuntimeException('Fixer.io: '.($answer['error']['info'] ?? $answer['error']['type'] ?? 'no rates in the answer'));
        }

        return ['fixer', array_map('floatval', $answer['rates'])];
    }

    /** @return array{string, array<string, float>} */
    private function fromEcb(): array
    {
        $xml = @simplexml_load_string($this->http->request('GET', self::ECB_URL, ['timeout' => self::TIMEOUT])->getContent());
        if (false === $xml) {
            throw new \RuntimeException('European Central Bank: the feed could not be read.');
        }
        $xml->registerXPathNamespace('ecb', 'http://www.ecb.int/vocabulary/2002-08-01/eurofxref');

        $rates = [];
        foreach ($xml->xpath('//ecb:Cube[@currency]') ?: [] as $cube) {
            $rates[(string) $cube['currency']] = (float) $cube['rate'];
        }
        if (!$rates) {
            throw new \RuntimeException('European Central Bank: no rates in the feed.');
        }

        return ['ecb', $rates];
    }
}
