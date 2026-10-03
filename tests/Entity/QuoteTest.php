<?php

namespace Tests\Base\Marketplace\Entity;

use Base\Marketplace\Entity\Quote;
use Base\Marketplace\Entity\Quote\QuoteLine;
use Base\Marketplace\Enum\Incoterm;
use Base\Marketplace\Enum\QuoteStatus;
use Base\Marketplace\Service\QuoteToOrder;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

/** A business quote: lines by the lot, terms, totals, what accepting makes of it. */
final class QuoteTest extends TestCase
{
    private function quote(): Quote
    {
        $quote = (new Quote('D-2026-0001'))->setTitle('Bordeaux pour Tokyo')->setCountry('jp')->setIncoterm(Incoterm::FOB)->setPlace('Le Havre');
        $quote->addLine(new QuoteLine(null, 20, 16200, 6, 'Pomerol 2019'));   // 20 cases of 6 at 162 €
        $quote->addLine(new QuoteLine(null, 10, 10000, 3, 'Coffret trois vins')); // 10 boxes of 3 at 100 €: does not share to the cent
        $quote->addLine(new QuoteLine(null, 1, 45000, 1, 'Transport jusqu\'au Havre'));

        return $quote;
    }

    public function testTotalsByTheLot(): void
    {
        $quote = $this->quote()->setDiscountPercent(5);
        self::assertSame('JP', $quote->getCountry());
        self::assertSame('FOB Le Havre', $quote->getTerms());
        self::assertSame(20 * 16200 + 10 * 10000 + 45000, $quote->getSubtotal());
        self::assertSame(120 + 30 + 1, $quote->getUnits());
        self::assertSame((int) round(469000 * 0.05), $quote->getDiscountAmount());
        self::assertSame(469000 - 23450, $quote->getTotal());
        self::assertSame([0, 1, 2], $quote->getLines()->map(fn (QuoteLine $l) => $l->getPosition())->getValues());
    }

    public function testOnlyASentValidQuoteWithSomethingToSellIsAcceptable(): void
    {
        $quote = $this->quote();
        self::assertFalse($quote->isAcceptable(), 'still a request');
        $quote->setStatus(QuoteStatus::SENT);
        self::assertTrue($quote->isAcceptable());
        $quote->setValidUntil(new \DateTimeImmutable('-1 day'));
        self::assertFalse($quote->isAcceptable(), 'lapsed');
        self::assertFalse((new Quote('D'))->setStatus(QuoteStatus::SENT)->isAcceptable(), 'nothing to sell');

        $quote->accept();
        self::assertSame(QuoteStatus::ACCEPTED, $quote->getStatus());
        $quote->markPaid('ORD-42');
        self::assertSame(QuoteStatus::PAID, $quote->getStatus());
        self::assertSame('ORD-42', $quote->getOrderReference());
    }

    public function testTheOrdersLinesShareTheLotsOutInUnitsWhenTheyDivide(): void
    {
        $quote = $this->quote()->setDiscountPercent(10);
        $items = (new QuoteToOrder($this->createMock(EntityManagerInterface::class)))->itemsOf($quote);

        [$line, $quantity, $unit, $comment] = $items[0];
        self::assertSame(120, $quantity, '20 cases of 6: 120 bottles');
        self::assertSame((int) round(2700 * 0.9), $unit, '162 € / 6 = 27 €, less 10 %');
        self::assertSame('20 × 6', $comment);

        [, $quantity, $unit] = $items[1];
        self::assertSame(10, $quantity, '100 € over 3 does not divide: by the box');
        self::assertSame(9000, $unit);

        [, $quantity, $unit, $comment] = $items[2];
        self::assertSame(1, $quantity);
        self::assertNull($comment);
    }
}
