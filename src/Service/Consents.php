<?php

namespace Base\Marketplace\Service;

use Base\Marketplace\Entity\Order;
use Base\Marketplace\Entity\Order\Consent;
use Base\Marketplace\Enum\Withdrawal;
use Base\Marketplace\Model\ConsentStatement;
use Base\Marketplace\Model\VatCustomerInterface;
use Base\Marketplace\Repository\Order\ConsentRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Translation\MessageCatalogueInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * What a buyer must state before paying an order, and the record of what they stated
 * (marketplace.consent, docs/withdrawal.md):
 *
 *   terms             whenever the host names its terms of sale (consent.terms_route);
 *   immediate_supply  when a line is digital content supplied at once (Enum\Withdrawal);
 *   early_service     when a line is a service performed from the payment on.
 *
 * The texts are translations (@marketplace.consent.*), which a host may word otherwise in its
 * own catalogue; each one is recorded word for word with its SHA-256 (Entity\Order\Consent),
 * and version() sums up a set of them - what a client that shows them sends back, so that what
 * was accepted is what was shown. Nothing is asked for an order whose lines are not marked and
 * a host that names no terms: the checkout is then as it was.
 *
 * The language: the one asked, else the current request's (the page's: /fr/... on a localised
 * site; the host's default under /upm), else the translator's, else kernel.default_locale - never
 * a fixed one. Each statement then says the language its text was found in: a language the host
 * has no text for falls back as its catalogues do, and the record says the language of the words
 * recorded, not the one asked.
 */
class Consents
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly TranslatorInterface $translator,
        private readonly UrlGeneratorInterface $router,
        #[Autowire('%marketplace.consent.terms_route%')] private readonly ?string $termsRoute = null,
        #[Autowire('%marketplace.consent.terms_route_parameters%')] private readonly array $termsRouteParameters = [],
        #[Autowire('%marketplace.consent.terms_version%')] private readonly ?string $termsVersion = null,
        #[Autowire('%marketplace.consent.businesses%')] private readonly string $businesses = 'ask',
        private readonly ?RequestStack $requests = null,
        #[Autowire('%kernel.default_locale%')] private readonly string $defaultLocale = 'en',
    ) {
    }

    /**
     * The statements this order asks of its buyer, in the order they are shown, worded in
     * $locale (locale() when null); each one's locale is the language its text is in.
     *
     * @return list<ConsentStatement>
     */
    public function statementsFor(Order $order, ?string $locale = null): array
    {
        $locale = null !== $locale && '' !== $locale ? $locale : $this->locale();
        $statements = [];
        if (null !== ($url = $this->termsUrl())) {
            $statements[] = $this->statement(ConsentStatement::TERMS, $locale, $url);
        }
        if ($this->asksWithdrawal($order)) {
            $kinds = [];
            foreach ($order->getItems() as $item) {
                if ($product = $item->getProduct()) {
                    $kinds[$product->getWithdrawal()->value] = true;
                }
            }
            if (isset($kinds[Withdrawal::DIGITAL_CONTENT->value])) {
                $statements[] = $this->statement(ConsentStatement::IMMEDIATE_SUPPLY, $locale);
            }
            if (isset($kinds[Withdrawal::SERVICE->value])) {
                $statements[] = $this->statement(ConsentStatement::EARLY_SERVICE, $locale);
            }
        }

        return $statements;
    }

    /**
     * The version of a set of statements: a SHA-256 of their ids, their texts' hashes and the
     * terms' version. Another wording, another language, a box more or less: another version.
     *
     * @param list<ConsentStatement> $statements
     */
    public function version(array $statements): string
    {
        $lines = array_map(static fn (ConsentStatement $statement) => $statement->id.':'.$statement->hash, $statements);
        $lines[] = 'terms_version:'.($this->termsVersion ?? '');

        return hash('sha256', implode("\n", $lines));
    }

    /**
     * The statements its buyer just made, recorded on the order - in place of those of an
     * earlier attempt to pay it that did not go through: what is kept is what the contract was
     * made on. Persisted; the caller flushes.
     *
     * @param list<ConsentStatement> $statements
     *
     * @return list<Consent>
     */
    public function record(Order $order, array $statements, string $channel, ?\DateTimeImmutable $at = null): array
    {
        foreach ($this->of($order) as $earlier) {
            $this->entityManager->remove($earlier);
        }
        $at ??= new \DateTimeImmutable();
        $recorded = [];
        foreach ($statements as $statement) {
            $this->entityManager->persist($recorded[] = new Consent($order, $statement, $channel, $this->termsVersion, $at));
        }

        return $recorded;
    }

    /** @return list<Consent> what the order's buyer stated before paying it */
    public function of(Order $order): array
    {
        $repository = $this->entityManager->getRepository(Consent::class);

        return $repository instanceof ConsentRepository ? $repository->ofOrder($order) : [];
    }

    /** The terms of sale's page, absolute; null when the host names none. */
    public function termsUrl(): ?string
    {
        if (null === $this->termsRoute || '' === $this->termsRoute) {
            return null;
        }

        return $this->router->generate($this->termsRoute, $this->termsRouteParameters, UrlGeneratorInterface::ABSOLUTE_URL);
    }

    public function termsVersion(): ?string
    {
        return $this->termsVersion;
    }

    /**
     * Whether the withdrawal statements are asked of this order's buyer: of every buyer, unless
     * consent.businesses is "skip" and the buyer has a confirmed VAT number.
     */
    public function asksWithdrawal(Order $order): bool
    {
        if ('skip' !== $this->businesses) {
            return true;
        }
        $customer = $order->getCustomer();

        return !($customer instanceof VatCustomerInterface && $customer->hasConfirmedVatNumber());
    }

    /**
     * The language the statements are worded in when none is asked: the current request's, else
     * the translator's (a translator that can say it, LocaleAwareInterface or not), else
     * kernel.default_locale.
     */
    public function locale(): string
    {
        $request = $this->requests?->getCurrentRequest();
        if (null !== $request && '' !== $request->getLocale()) {
            return $request->getLocale();
        }
        if (method_exists($this->translator, 'getLocale')) {
            $locale = $this->translator->getLocale();
            if (\is_string($locale) && '' !== $locale) {
                return $locale;
            }
        }

        return $this->defaultLocale;
    }

    private function statement(string $id, string $locale, ?string $url = null): ConsentStatement
    {
        $found = $this->foundIn('consent.'.$id, $locale);

        return new ConsentStatement($id, $this->translator->trans('@marketplace.consent.'.$id, [], null, $found), $found, $url);
    }

    /**
     * The language the text of $key is found in when asked in $locale: $locale, its language
     * without region (fr_FR: fr), then the translator's fallbacks - as the catalogues fall back.
     * $locale when the translator cannot say (no catalogue to read).
     */
    private function foundIn(string $key, string $locale): string
    {
        if (!method_exists($this->translator, 'getCatalogue')) {
            return $locale;
        }
        $locale = str_replace('-', '_', $locale);
        $candidates = [$locale, explode('_', $locale)[0]];
        if (method_exists($this->translator, 'getFallbackLocales')) {
            array_push($candidates, ...array_values(array_filter((array) $this->translator->getFallbackLocales(), 'is_string')));
        }
        $candidates[] = $this->defaultLocale;
        foreach (array_unique($candidates) as $candidate) {
            try {
                $catalogue = $this->translator->getCatalogue($candidate);
            } catch (\Throwable) {
                continue;
            }
            if ($catalogue instanceof MessageCatalogueInterface && $catalogue->defines($key, 'marketplace')) {
                return $candidate;
            }
        }

        return $locale;
    }
}
