<?php

namespace Base\Marketplace\Service;

use Base\Marketplace\Entity\Product;
use Base\Marketplace\Entity\Product\Taxon;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * The age a visitor must have reached to see what is sold to adults only
 * (a wine, a sake: Product::isAgeRestricted()), and whether they said so.
 *
 * The age is set by country or by language (marketplace.age_gate.ages: 18
 * by default, 20 in Japan and in Japanese), the country read from the
 * visitor's country cookie or header when the application sets one. The
 * answer is kept in a cookie for a year (a necessary cookie: the law asks
 * for the check); a "no" sends the visitor away.
 */
class AgeGate
{
    public const COOKIE = 'marketplace_age';

    /** @param array<string, int> $ages by language ("ja") or country ("JP") */
    public function __construct(
        private readonly RequestStack $requests,
        #[Autowire('%marketplace.age_gate.enabled%')] private readonly bool $enabled = true,
        #[Autowire('%marketplace.age_gate.default%')] private readonly int $default = 18,
        #[Autowire('%marketplace.age_gate.ages%')] private readonly array $ages = [],
        #[Autowire('%marketplace.age_gate.notice%')] private readonly ?string $notice = null,
        #[Autowire('%marketplace.age_gate.site%')] private readonly bool $site = false,
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /** The age asked of this visitor: their country's, else their language's, else the default. */
    public function minimumAge(?string $locale = null, ?string $country = null): int
    {
        $request = $this->requests->getCurrentRequest();
        $locale ??= $request?->getLocale();
        $country ??= $this->countryOf($request);
        $ages = array_change_key_case($this->ages, \CASE_LOWER);

        if ($country && isset($ages[strtolower($country)])) {
            return (int) $ages[strtolower($country)];
        }
        if ($locale) {
            $lang = strtolower(substr($locale, 0, 2));
            if (isset($ages[strtolower($locale)])) {
                return (int) $ages[strtolower($locale)];
            }
            if (isset($ages[$lang])) {
                return (int) $ages[$lang];
            }
        }

        return $this->default;
    }

    /**
     * Whether this page needs the gate: the whole site when age_gate.site is
     * true, else a restricted product or taxon, or `true` given by a page
     * that is (a cellar's list).
     */
    public function guards(Product|Taxon|bool|null $subject = null): bool
    {
        if (!$this->enabled) {
            return false;
        }
        if ($this->site || true === $subject) {
            return true;
        }

        return ($subject instanceof Product || $subject instanceof Taxon) && $subject->isAgeRestricted();
    }

    /** Whether the visitor confirmed the age this page asks (a lower one does not count for a higher one). */
    public function isConfirmed(?Request $request = null): bool
    {
        $request ??= $this->requests->getCurrentRequest();
        $said = (int) ($request?->cookies->get(self::COOKIE) ?? 0);

        return $said > 0 && $said >= $this->minimumAge();
    }

    /** Whether to show the gate now. */
    public function mustAsk(Product|Taxon|bool|null $subject = null): bool
    {
        return $this->guards($subject) && !$this->isConfirmed();
    }

    /** The cookie keeping the visitor's yes: the age they confirmed, for a year. */
    public function confirmation(?int $age = null): Cookie
    {
        return Cookie::create(self::COOKIE, (string) ($age ?? $this->minimumAge()), new \DateTimeImmutable('+1 year'), '/', null, null, true, false, Cookie::SAMESITE_LAX);
    }

    /** The health notice the law asks next to alcohol (France: loi Évin), as configured; null: none. */
    public function notice(): ?string
    {
        return $this->notice ?: null;
    }

    private function countryOf(?Request $request): ?string
    {
        if (!$request) {
            return null;
        }
        $country = $request->cookies->get('country') ?? $request->headers->get('CF-IPCountry') ?? $request->headers->get('X-Country');

        return \is_string($country) && preg_match('/^[A-Za-z]{2}$/', $country) ? strtoupper($country) : null;
    }
}
