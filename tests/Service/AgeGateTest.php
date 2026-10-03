<?php

namespace Tests\Base\Marketplace\Service;

use Base\Marketplace\Entity\Product;
use Base\Marketplace\Entity\Product\Taxon;
use Base\Marketplace\Service\AgeGate;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/** 18 by default, 20 in Japanese or in Japan; asked once, before what is restricted. */
final class AgeGateTest extends TestCase
{
    private function gate(Request $request, bool $site = false): AgeGate
    {
        $stack = new RequestStack();
        $stack->push($request);

        return new AgeGate($stack, true, 18, ['ja' => 20, 'JP' => 20], 'L\'abus d\'alcool est dangereux pour la santé.', $site);
    }

    public function testTheAgeByLanguageAndCountry(): void
    {
        $fr = new Request();
        $fr->setLocale('fr');
        self::assertSame(18, $this->gate($fr)->minimumAge());

        $ja = new Request();
        $ja->setLocale('ja');
        self::assertSame(20, $this->gate($ja)->minimumAge());

        $inJapan = new Request(cookies: ['country' => 'jp']);
        $inJapan->setLocale('en');
        self::assertSame(20, $this->gate($inJapan)->minimumAge(), 'the country wins');
        self::assertSame(18, $this->gate($inJapan)->minimumAge('fr', 'FR'));
    }

    public function testOnlyRestrictedThingsAreGuarded(): void
    {
        $gate = $this->gate(new Request());
        $wine = $this->createMock(Product::class);
        $wine->method('isAgeRestricted')->willReturn(true);
        $glass = $this->createMock(Product::class);
        $glass->method('isAgeRestricted')->willReturn(false);
        $taxon = $this->createMock(Taxon::class);
        $taxon->method('isAgeRestricted')->willReturn(true);

        self::assertTrue($gate->guards($wine));
        self::assertFalse($gate->guards($glass));
        self::assertTrue($gate->guards($taxon));
        self::assertTrue($gate->guards(true), 'a page restricted as a whole');
        self::assertFalse($gate->guards(null));
        self::assertTrue($this->gate(new Request(), site: true)->guards(null), 'every page');
    }

    public function testAYesIsKeptAndALowerAgeDoesNotOpenAHigherGate(): void
    {
        $fr = new Request(cookies: [AgeGate::COOKIE => '18']);
        $fr->setLocale('fr');
        self::assertTrue($this->gate($fr)->isConfirmed());
        self::assertFalse($this->gate($fr)->mustAsk(true));

        $ja = new Request(cookies: [AgeGate::COOKIE => '18']);
        $ja->setLocale('ja');
        self::assertFalse($this->gate($ja)->isConfirmed(), '18 said, 20 asked');
        self::assertTrue($this->gate($ja)->mustAsk(true));

        $cookie = $this->gate($ja)->confirmation();
        self::assertSame(AgeGate::COOKIE, $cookie->getName());
        self::assertSame('20', $cookie->getValue());
        self::assertGreaterThan(time() + 300 * 86400, $cookie->getExpiresTime());
    }

    public function testTheNotice(): void
    {
        self::assertStringContainsString('abus d\'alcool', (string) $this->gate(new Request())->notice());
    }
}
