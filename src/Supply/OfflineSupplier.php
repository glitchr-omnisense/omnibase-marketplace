<?php

namespace Base\Marketplace\Supply;

use Base\Marketplace\Entity\Order\SupplyJob;
use Base\Marketplace\Enum\SupplyStatus;
use Base\Marketplace\Supply\Model\SupplyOrder;
use Base\Marketplace\Supply\Model\SupplyProduct;
use Base\Marketplace\Supply\Model\SupplyQuote;
use Base\Marketplace\Supply\Model\SupplyResult;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\UriSigner;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * A supplier without an API: the shop's partner workshop, a local printer.
 * An order reaches them as a brief by e-mail - what to make, from which
 * files, for whom - with a signed link to a page where they accept it, say
 * where it stands and give the parcel's tracking (Controller\Client\
 * SupplyController); nothing to install on their side.
 *
 *     marketplace:
 *         supply:
 *             offline: { email: 'atelier@example.org', name: 'Atelier Dupont', link_ttl: 7776000 }
 *
 * What it makes is whatever the shop's products say: a Product's
 * supplierReference is the workshop's own name for it.
 */
class OfflineSupplier implements SupplierInterface
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UrlGeneratorInterface $urls,
        private readonly UriSigner $signer,
        private readonly ?MailerInterface $mailer = null,
        #[Autowire('%marketplace.supply.offline.email%')] private readonly ?string $email = null,
        #[Autowire('%marketplace.supply.offline.name%')] private readonly ?string $partner = null,
        #[Autowire('%marketplace.supply.offline.link_ttl%')] private readonly int $linkTtl = 7776000,
        private readonly ?TranslatorInterface $translator = null,
    ) {
    }

    public static function name(): string
    {
        return 'offline';
    }

    public function isConfigured(): bool
    {
        return null !== $this->mailer && null !== $this->email && '' !== $this->email;
    }

    public function products(?string $query = null): array
    {
        return []; // the workshop has no catalogue to read: the shop's products name what it makes
    }

    public function quote(SupplyOrder $order): SupplyQuote
    {
        throw new SupplyException('offline', 'A workshop is asked its price by hand: no quote from here.');
    }

    public function submit(SupplyOrder $order, bool $draft = false): SupplyResult
    {
        if (!$this->isConfigured()) {
            throw new SupplyException('offline', 'No partner address (marketplace.supply.offline.email), or no mailer.');
        }
        // Its reference at the workshop is ours: there is nobody to give another.
        $link = $this->link($order->reference);
        try {
            $this->mailer->send((new TemplatedEmail())
                ->to(new Address($this->email, (string) $this->partner))
                ->subject($this->subject('brief.subject', $order->reference))
                ->htmlTemplate('@Marketplace/email/supply_brief.html.twig')
                ->context(['supply' => $order, 'link' => $link, 'draft' => $draft, 'partner' => $this->partner]));
        } catch (\Throwable $e) {
            throw new SupplyException('offline', 'The brief could not be sent: '.$e->getMessage(), $e);
        }

        return new SupplyResult('offline', $order->reference, $draft ? SupplyStatus::DRAFT : SupplyStatus::SUBMITTED, orderReference: $order->reference);
    }

    public function confirm(string $reference): SupplyResult
    {
        return new SupplyResult('offline', $reference, SupplyStatus::SUBMITTED, orderReference: $reference);
    }

    /** What the workshop last said on its page: kept on the job. */
    public function fetch(string $reference): SupplyResult
    {
        $job = $this->entityManager->getRepository(SupplyJob::class)->findOneBy(['supplier' => 'offline', 'reference' => $reference]);
        if (!$job) {
            throw new SupplyException('offline', sprintf('No job "%s".', $reference));
        }

        return new SupplyResult('offline', $reference, $job->getStatus(), $job->getTrackingNumber(), $job->getTrackingUrl(), $job->getCarrier(), $job->getMessage(), $reference);
    }

    public function cancel(string $reference): SupplyResult
    {
        if ($this->isConfigured()) {
            $this->mailer->send((new TemplatedEmail())
                ->to(new Address($this->email, (string) $this->partner))
                ->subject($this->subject('brief.cancelled_subject', $reference))
                ->htmlTemplate('@Marketplace/email/supply_brief.html.twig')
                ->context(['supply' => null, 'reference' => $reference, 'link' => $this->link($reference), 'cancelled' => true, 'draft' => false, 'partner' => $this->partner]));
        }

        return new SupplyResult('offline', $reference, SupplyStatus::CANCELLED, orderReference: $reference);
    }

    public function notify(string $body, array $headers = []): ?SupplyResult
    {
        return null; // it answers through its page, not a webhook
    }

    private function subject(string $key, string $reference): string
    {
        return $this->translator?->trans('@marketplace.supply.'.$key, ['{reference}' => $reference]) ?? $reference;
    }

    /** The workshop's page for a job: signed, valid marketplace.supply.offline.link_ttl seconds (90 days). */
    public function link(string $reference): string
    {
        return $this->signer->sign($this->urls->generate('marketplace_supply_offline', ['reference' => $reference], UrlGeneratorInterface::ABSOLUTE_URL), new \DateTimeImmutable(sprintf('+%d seconds', $this->linkTtl)));
    }
}
