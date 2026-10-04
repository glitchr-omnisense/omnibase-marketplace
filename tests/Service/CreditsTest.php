<?php

namespace Tests\Base\Marketplace\Service;

use Base\Marketplace\Service\CreditException;
use Base\Marketplace\Service\Credits;
use Tests\Base\Marketplace\MarketplaceKernelTestCase;

/** The credit ledger: grants, spendings, expiry, kinds kept apart. */
final class CreditsTest extends MarketplaceKernelTestCase
{
    public function testGrantedSpentAndRefused(): void
    {
        $credits = new Credits($this->entityManager);
        $host = $this->user('host');
        self::assertSame(0, $credits->balance($host, 'ai'));

        $credits->grant($host, 'ai', 3, 'pack');
        $credits->grant($host, 'stationery', 2000);
        self::assertSame(3, $credits->balance($host, 'ai'));
        self::assertTrue($credits->has($host, 'ai', 3));

        $spent = $credits->spend($host, 'ai', 2, 'answers', 'occasion', 7);
        self::assertSame(-2, $spent->getQuantity());
        self::assertSame('7', $spent->getResourceId());
        self::assertSame(1, $credits->balance($host, 'ai'));
        self::assertSame(2000, $credits->balance($host, 'stationery'), 'kinds are kept apart');

        try {
            $credits->spend($host, 'ai', 2);
            self::fail('Two asked, one left.');
        } catch (CreditException $e) {
            self::assertSame(1, $e->balance);
        }
        self::assertTrue($this->entityManager->isOpen());
        self::assertSame(1, $credits->balance($host, 'ai'), 'nothing was taken');

        $credits->refund($spent, 'the answer failed');
        self::assertSame(3, $credits->balance($host, 'ai'));
    }

    public function testWhatAnExpiredGrantHadLeftIsLost(): void
    {
        $credits = new Credits($this->entityManager);
        $host = $this->user('host');
        $credits->grant($host, 'ai', 10, 'plan', new \DateTimeImmutable('+1 month'));
        $credits->grant($host, 'ai', 5, 'pack');
        $credits->spend($host, 'ai', 4);

        self::assertSame(11, $credits->balance($host, 'ai'));
        // In two months the plan's 10 lapsed: the 4 spent came out of them, the pack's 5 are whole.
        self::assertSame(5, $credits->balance($host, 'ai', new \DateTimeImmutable('+2 months')));

        $credits->spend($host, 'ai', 8);
        // 12 spent: 10 from the lapsed grant, 2 from the pack.
        self::assertSame(3, $credits->balance($host, 'ai', new \DateTimeImmutable('+2 months')));
    }
}
