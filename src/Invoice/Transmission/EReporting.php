<?php

namespace Base\Marketplace\Invoice\Transmission;

use Base\Marketplace\Entity\Invoice;
use Base\Marketplace\Entity\Order;
use Base\Marketplace\Enum\InvoiceRoute;
use Base\Marketplace\Invoice\InvoiceComposer;
use Base\Marketplace\Invoice\Transmission\Entity\EReport;
use Base\Marketplace\Pricing\ReverseCharge;
use Doctrine\ORM\EntityManagerInterface;
use Omnibill\Exception\OmnibillException;
use Omnibill\Exception\UnreachableException;
use Omnibill\Model\EReport as BillReport;
use Omnibill\Model\Syntax;
use Omnibill\Registry;
use Omnibill\Request\Report;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The shop's e-reporting (CGI art. 290 and 290 A, ann. II art. 242 nonies M
 * and P): what an approved platform must receive of the sales that are no
 * e-invoice, period by period, as the seller's VAT regime sets the periods
 * (marketplace.invoice.vat_regime).
 *
 * Collected, as impots.gouv.fr's summaries of August 2026 list it
 * ("Données de transaction", "Données de paiement", "Fréquences et délais de
 * transmission"):
 *
 *   - transactions with private persons (Enum\InvoiceRoute::B2C), aggregated
 *     by day: for each category - goods, services, distance sales to another
 *     EU country - and each VAT rate, the base before VAT and the VAT, in
 *     euros; credit notes take their amounts off the day they are issued;
 *   - transactions with companies established abroad (INTERNATIONAL_B2B),
 *     invoice by invoice: the SIREN and the buyer's identifier (its VAT
 *     number), the countries, the category (LB, PS, LBPS), the date, the
 *     number, the totals by rate, the currency, the exemption, the invoice
 *     corrected; the lines, the charges and the discounts (due from
 *     2027-09-01);
 *   - payments, only for operations whose VAT is due on payment (services,
 *     without the option for the debits): by day for private persons, by
 *     invoice for companies abroad, the amount received by rate. The
 *     payments of e-invoices are declared on the invoice itself ("Encaissée",
 *     InvoiceTransmission).
 *
 * The file is the application's to write (EReportWriterInterface: the
 * DGFiP's format, not read for this bundle); without a writer each period
 * stays COLLECTED, its data kept on its EReport. With one, it is submitted
 * to the approved platform (Omnibill\Request\Report).
 *
 * The day of a sale is the order's payment - the operation's own date (a
 * delivery) is not known to the shop; amounts in another currency than the
 * euro are not converted, but listed in the warnings.
 */
class EReporting
{
    public const REGIMES = ['normal_monthly', 'normal_quarterly', 'simplified', 'franchise'];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly InvoiceComposer $composer,
        private readonly InvoiceTransmission $transmission,
        private readonly ?Registry $registry = null,
        private readonly ?EReportWriterInterface $writer = null,
        #[Autowire('%marketplace.invoice.vat_regime%')] private readonly ?string $regime = null,
        #[Autowire('%marketplace.invoice.operation%')] private readonly string $operation = 'goods',
        #[Autowire('%marketplace.invoice.vat_on_debits%')] private readonly bool $vatOnDebits = false,
        #[Autowire('%marketplace.invoice.gateway%')] private readonly ?string $gateway = null,
        #[Autowire('%marketplace.invoice.seller%')] private readonly array $seller = [],
    ) {
    }

    /** Whether the shop e-reports: a VAT regime is configured. */
    public function isEnabled(): bool
    {
        return \in_array($this->regime, self::REGIMES, true);
    }

    /** Whether payments are e-reported: operations whose VAT is due on payment - services, without the option for the debits. */
    public function reportsPayments(): bool
    {
        return !$this->vatOnDebits && \in_array($this->operation, ['services', 'mixed'], true);
    }

    /**
     * The period of that kind containing a day, and its deadline, as the
     * regime's table says.
     *
     * @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable, 2: \DateTimeImmutable} its first day, its last day, the last day to transmit it
     */
    public static function period(string $regime, string $kind, \DateTimeImmutable $day): array
    {
        $day = $day->setTime(0, 0);
        $month = $day->modify('first day of this month');
        $next = $month->modify('+1 month');
        $bimonth = static function () use ($day): array {
            $first = $day->setDate((int) $day->format('Y'), (int) $day->format('n') - ((int) $day->format('n') + 1) % 2, 1);

            return [$first, $first->modify('+1 month')->modify('last day of this month'), $first->modify('+2 months')->setDate((int) $first->modify('+2 months')->format('Y'), (int) $first->modify('+2 months')->format('n'), 25)];
        };

        if ('franchise' === $regime) {
            return $bimonth();
        }
        if (EReport::TRANSACTIONS === $kind && 'normal_monthly' === $regime) {
            $d = (int) $day->format('j');
            $last = (int) $month->format('t');
            if ($d <= 10) {
                return [$month, $month->setDate((int) $month->format('Y'), (int) $month->format('n'), 10), $month->setDate((int) $month->format('Y'), (int) $month->format('n'), 20)];
            }
            if ($d <= 20) {
                // Ten days after: the 30th - but for February, whose last day it is.
                return [$month->setDate((int) $month->format('Y'), (int) $month->format('n'), 11), $month->setDate((int) $month->format('Y'), (int) $month->format('n'), 20), $month->setDate((int) $month->format('Y'), (int) $month->format('n'), min(30, $last))];
            }

            return [$month->setDate((int) $month->format('Y'), (int) $month->format('n'), 21), $month->modify('last day of this month'), $next->setDate((int) $next->format('Y'), (int) $next->format('n'), 10)];
        }
        // A month: before the 10th of the next for the normal regimes, by the 25th for the simplified one.
        $due = 'simplified' === $regime ? 25 : 10;

        return [$month, $month->modify('last day of this month'), $next->setDate((int) $next->format('Y'), (int) $next->format('n'), $due)];
    }

    /**
     * The period of that kind collected again, or for the first time: the one
     * containing $day, or the last one ended before today. A period sent is
     * left as it is.
     */
    public function collect(string $kind, ?\DateTimeImmutable $day = null): EReport
    {
        $regime = $this->regime ?? throw new \LogicException('E-reporting needs the seller\'s VAT regime: marketplace.invoice.vat_regime.');
        if (null === $day) {
            [$current] = self::period($regime, $kind, new \DateTimeImmutable('today'));
            $day = $current->modify('-1 day');
        }
        [$from, $to, $due] = self::period($regime, $kind, $day);
        $report = $this->entityManager->getRepository(EReport::class)->findOneBy(['kind' => $kind, 'startsOn' => $from]);
        if (null === $report) {
            $report = new EReport($kind, $from, $to, $due, $regime);
            $this->entityManager->persist($report);
        }
        if (!$report->isSent()) {
            $report->collected(EReport::TRANSACTIONS === $kind ? $this->transactions($from, $to) : $this->payments($from, $to));
        }
        $this->entityManager->flush();

        return $report;
    }

    /**
     * Sent to the approved platform, once: the application's writer makes the
     * file, the platform takes it. Without a writer or a platform it stays
     * collected, and says why.
     */
    public function send(EReport $report): EReport
    {
        if ($report->isSent()) {
            return $report;
        }
        $name = $this->gateway;
        if (null === $name || !$this->transmission->isPlatform($name) || !$this->registry->get($name)->supports(Report::class)) {
            $report->notSent('No approved platform takes e-reporting files (marketplace.invoice.gateway).');
        } elseif (null === $this->writer || null === ($file = $this->writer->write($report))) {
            $report->notSent('No e-reporting file writer (Invoice\\Transmission\\EReportWriterInterface): the data is kept here.');
        } else {
            try {
                $flow = $this->registry->get($name)->report(new BillReport($file['content'], $file['filename'], Syntax::FRR, $file['rule'] ?? null), sprintf('%s-%s', $report->getKind(), $report->getStartsOn()->format('Ymd')));
                $report->sent($name, $flow->reference);
            } catch (UnreachableException $e) {
                $report->notSent($e->getMessage());
            } catch (OmnibillException $e) {
                $report->notSent($e->getMessage(), true);
            }
        }
        $this->entityManager->flush();

        return $report;
    }

    /**
     * @return array{siren: ?string, regime: string, period: array{from: string, to: string}, vat_on_debits: bool, currency: string, b2c: list<array<string, mixed>>, b2b_international: list<array<string, mixed>>, warnings: list<string>}
     */
    public function transactions(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $data = $this->header($from, $to) + ['b2c' => [], 'b2b_international' => [], 'warnings' => []];
        $days = [];
        foreach ($this->paidOrders($from, $to) as $order) {
            $invoice = $this->firstInvoice($order);
            [$seller, $buyer, $totals] = null !== $invoice ? [$invoice->getSeller(), $invoice->getBuyer(), $invoice->getTotals()] : [$this->composer->seller($order), $this->composer->buyer($order), $this->composer->amounts($order)['totals']];
            $route = InvoiceRoute::of($seller, $buyer);
            if (InvoiceRoute::B2C === $route) {
                if ($this->euro($order->getCurrency(), $order->getReference(), $data['warnings'])) {
                    $this->add($days, \DateTimeImmutable::createFromInterface($order->getPaidAt())->format('Y-m-d'), $this->category($buyer, $data['warnings'], $order->getReference()), $totals, 1);
                }
            } elseif (InvoiceRoute::INTERNATIONAL_B2B === $route && null === $invoice) {
                // Reported invoice by invoice, on the invoice's date (below): this one has none yet.
                $data['warnings'][] = sprintf('The order %s, sold to a company abroad, has no invoice: it is reported once invoiced.', $order->getReference());
            }
        }
        foreach ($this->invoicesIssued($from, $to) as $invoice) {
            $route = InvoiceRoute::of($invoice->getSeller(), $invoice->getBuyer());
            if (InvoiceRoute::INTERNATIONAL_B2B === $route) {
                $data['b2b_international'][] = $this->invoiceData($invoice);
            } elseif (InvoiceRoute::B2C === $route && $invoice->isCreditNote() && $this->euro($invoice->getCurrency(), $invoice->getNumber(), $data['warnings'])) {
                $this->add($days, $invoice->getIssuedAt()->format('Y-m-d'), $this->category($invoice->getBuyer(), $data['warnings'], $invoice->getNumber()), $invoice->getTotals(), -1);
            }
        }
        ksort($days);
        foreach ($days as $date => $categories) {
            ksort($categories);
            foreach ($categories as $category => $rates) {
                ksort($rates);
                $data['b2c'][] = ['date' => $date, 'category' => $category, 'rates' => array_values(array_map(static fn (array $r) => ['rate' => $r['rate'], 'base' => self::euros($r['base']), 'vat' => self::euros($r['vat'])], $rates))];
            }
        }
        $data['warnings'] = array_values(array_unique($data['warnings']));

        return $data;
    }

    /**
     * @return array{siren: ?string, regime: string, period: array{from: string, to: string}, vat_on_debits: bool, currency: string, b2c: list<array<string, mixed>>, b2b_international: list<array<string, mixed>>, warnings: list<string>}
     */
    public function payments(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $data = $this->header($from, $to) + ['b2c' => [], 'b2b_international' => [], 'warnings' => []];
        if (!$this->reportsPayments()) {
            $data['warnings'][] = 'No payment is e-reported: the operations are goods, or the seller opted for the debits.';

            return $data;
        }
        if ('mixed' === $this->operation) {
            $data['warnings'][] = 'Goods and services are mixed (marketplace.invoice.operation): every payment is reported, the goods\' included - to sort out by hand.';
        }
        $days = [];
        foreach ($this->paidOrders($from, $to) as $order) {
            $invoice = $this->firstInvoice($order);
            [$seller, $buyer, $totals] = null !== $invoice ? [$invoice->getSeller(), $invoice->getBuyer(), $invoice->getTotals()] : [$this->composer->seller($order), $this->composer->buyer($order), $this->composer->amounts($order)['totals']];
            $paidOn = \DateTimeImmutable::createFromInterface($order->getPaidAt())->format('Y-m-d');
            $route = InvoiceRoute::of($seller, $buyer);
            if (!$this->euro($order->getCurrency(), $order->getReference(), $data['warnings'])) {
                continue;
            }
            if (InvoiceRoute::B2C === $route) {
                foreach ($totals['vat_breakdown'] ?? [] as $vat) {
                    $key = (string) $vat['rate'];
                    $days[$paidOn][$key] ??= ['rate' => (float) $vat['rate'], 'amount' => 0];
                    $days[$paidOn][$key]['amount'] += (int) $vat['base'] + (int) $vat['vat'];
                }
            } elseif (InvoiceRoute::INTERNATIONAL_B2B === $route) {
                if (null === $invoice) {
                    $data['warnings'][] = sprintf('The order %s, sold to a company abroad, has no invoice: its payment is reported by invoice.', $order->getReference());
                    continue;
                }
                $data['b2b_international'][] = ['number' => $invoice->getNumber(), 'paid_on' => $paidOn, 'rates' => array_map(static fn (array $v) => ['rate' => (float) $v['rate'], 'amount' => self::euros((int) $v['base'] + (int) $v['vat'])], $totals['vat_breakdown'] ?? [])];
            }
        }
        ksort($days);
        foreach ($days as $date => $rates) {
            ksort($rates);
            $data['b2c'][] = ['date' => $date, 'rates' => array_values(array_map(static fn (array $r) => ['rate' => $r['rate'], 'amount' => self::euros($r['amount'])], $rates))];
        }
        $data['warnings'] = array_values(array_unique($data['warnings']));

        return $data;
    }

    /** @return array{siren: ?string, regime: string, period: array{from: string, to: string}, vat_on_debits: bool, currency: string} */
    private function header(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $siren = (string) ($this->seller['siren'] ?? '');

        return ['siren' => '' !== $siren ? $siren : null, 'regime' => (string) $this->regime, 'period' => ['from' => $from->format('Y-m-d'), 'to' => $to->format('Y-m-d')], 'vat_on_debits' => $this->vatOnDebits, 'currency' => 'EUR'];
    }

    /** @return array<string, mixed> one invoice, as the e-reporting of a company abroad lists it */
    private function invoiceData(Invoice $invoice): array
    {
        $seller = $invoice->getSeller();
        $buyer = $invoice->getBuyer();
        $totals = $invoice->getTotals();
        $money = fn (int $amount): string => InvoiceTransmission::decimal($amount, $invoice->getCurrency());

        return [
            'number' => $invoice->getNumber(),
            'type' => $invoice->isCreditNote() ? '381' : '380',
            'issued_on' => $invoice->getIssuedAt()->format('Y-m-d'),
            'sold_on' => $invoice->getSoldAt()?->format('Y-m-d'),
            'corrects' => null !== ($credited = $invoice->getCredited()) ? ['number' => $credited->getNumber(), 'issued_on' => $credited->getIssuedAt()->format('Y-m-d')] : null,
            'seller' => ['siren' => $seller['siren'] ?? null, 'vat_number' => $seller['vat_number'] ?? null, 'country' => $seller['country'] ?? 'FR'],
            'buyer' => ['name' => $buyer['name'] ?? null, 'identifier' => $buyer['vat_number'] ?? null, 'country' => $buyer['address']['country'] ?? null],
            'category' => match ($this->operation) { 'services' => 'PS', 'mixed' => 'LBPS', default => 'LB' },
            'currency' => $invoice->getCurrency(),
            'rates' => array_map(static fn (array $v) => ['rate' => (float) $v['rate'], 'category' => $v['category'] ?? null, 'base' => $money((int) $v['base']), 'vat' => $money((int) $v['vat'])], $totals['vat_breakdown'] ?? []),
            'total_without_vat' => $money((int) ($totals['total_without_vat'] ?? 0)),
            'vat' => $money((int) ($totals['vat'] ?? 0)),
            'exemption' => $totals['exemption']['reason'] ?? null,
            'vat_on_debits' => $this->vatOnDebits,
            // Due from 2027-09-01: the lines, the charges and discounts, the delivery address.
            'lines' => array_map(static fn (array $l) => ['label' => $l['label'] ?? null, 'quantity' => $l['quantity'] ?? null, 'unit_price' => $money((int) round((float) ($l['unit_price'] ?? 0))), 'rate' => (float) ($l['rate'] ?? 0)], $invoice->getLines()),
            'charges' => array_map(static fn (array $c) => ['kind' => $c['kind'], 'rate' => (float) $c['rate'], 'amount' => $money((int) $c['total']), 'vat' => $money((int) $c['vat'])], $totals['charges'] ?? []),
            'allowances' => array_map(static fn (array $c) => ['kind' => $c['kind'], 'rate' => (float) $c['rate'], 'amount' => $money((int) $c['total']), 'vat' => $money((int) $c['vat'])], $totals['allowances'] ?? []),
            'delivery' => $buyer['delivery'] ?? null,
        ];
    }

    /**
     * @param array<string, array<string, array<string, array{rate: float, base: int, vat: int}>>> $days
     * @param array<string, mixed>                                                                 $totals
     */
    private function add(array &$days, string $date, string $category, array $totals, int $sign): void
    {
        foreach ($totals['vat_breakdown'] ?? [] as $vat) {
            $key = sprintf('%07.3f', (float) $vat['rate']);
            $days[$date][$category][$key] ??= ['rate' => (float) $vat['rate'], 'base' => 0, 'vat' => 0];
            $days[$date][$category][$key]['base'] += $sign * (int) $vat['base'];
            $days[$date][$category][$key]['vat'] += $sign * (int) $vat['vat'];
        }
    }

    /**
     * The category of a sale to a private person: goods or services subject
     * to VAT here, or a distance sale to another EU country (CGI art. 258 A,
     * 259 B). Outside the EU, an export: none of the summary's four, listed
     * on its own for whoever writes the file to decide.
     *
     * @param array<string, mixed> $buyer
     * @param list<string>         $warnings
     */
    private function category(array $buyer, array &$warnings, string $what): string
    {
        $country = strtoupper((string) ($buyer['delivery']['country'] ?? $buyer['address']['country'] ?? ''));
        if ('' !== $country && 'FR' !== $country) {
            if (\in_array('GR' === $country ? 'EL' : $country, ReverseCharge::EU, true)) {
                return 'intra_eu';
            }
            $warnings[] = sprintf('%s: sold to a private person outside the EU (%s), reported as an export.', $what, $country);

            return 'export';
        }
        if ('mixed' === $this->operation) {
            $warnings[] = 'Goods and services are mixed (marketplace.invoice.operation): the sales to private persons are reported as goods - to sort out by hand.';
        }

        return 'services' === $this->operation ? 'services' : 'goods';
    }

    /** @param list<string> $warnings */
    private function euro(string $currency, string $what, array &$warnings): bool
    {
        if ('EUR' === $currency) {
            return true;
        }
        $warnings[] = sprintf('%s is in %s: e-reporting is in euros, it is left out - convert it by hand.', $what, $currency);

        return false;
    }

    /** @return iterable<Order> the orders paid in the period */
    private function paidOrders(\DateTimeImmutable $from, \DateTimeImmutable $to): iterable
    {
        return $this->entityManager->getRepository(Order::class)->createQueryBuilder('o')
            ->where('o.paidAt >= :from')->setParameter('from', $from)
            ->andWhere('o.paidAt < :to')->setParameter('to', $to->modify('+1 day'))
            ->orderBy('o.paidAt', 'ASC')
            ->getQuery()->toIterable();
    }

    /** @return list<Invoice> the invoices and credit notes issued in the period */
    private function invoicesIssued(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        return $this->entityManager->getRepository(Invoice::class)->createQueryBuilder('i')
            ->where('i.issuedAt >= :from')->setParameter('from', $from)
            ->andWhere('i.issuedAt < :to')->setParameter('to', $to->modify('+1 day'))
            ->orderBy('i.issuedAt', 'ASC')
            ->getQuery()->getResult();
    }

    /** The order's first invoice: what it was sold as, even if a credit note cancelled it since. */
    private function firstInvoice(Order $order): ?Invoice
    {
        foreach ($this->entityManager->getRepository(Invoice::class)->ofOrder($order) as $invoice) {
            if (!$invoice->isCreditNote()) {
                return $invoice;
            }
        }

        return null;
    }

    /** Cents as euros: "12.34". */
    private static function euros(int $cents): string
    {
        return InvoiceTransmission::decimal($cents, 'EUR');
    }
}
