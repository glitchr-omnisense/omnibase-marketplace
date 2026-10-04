<?php

namespace Base\Marketplace\Wishlist;

use Base\Marketplace\Entity\Wishlist\Contribution;
use Base\Marketplace\Entity\Wishlist\Item;
use Base\Marketplace\Enum\ContributionStatus;
use Base\Marketplace\Enum\WishlistItemKind;
use Base\Marketplace\Event\ContributionPaidEvent;
use Base\Marketplace\Event\ContributionPreparingEvent;
use Base\Marketplace\Payment\Omnitrade\OmnitradeGateways;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Omnitrade\Exception\OmnitradeException;
use Omnitrade\Model\Customer;
use Omnitrade\Model\Line;
use Omnitrade\Model\Money;
use Omnitrade\Model\Payment;
use Omnitrade\Model\Status;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Money given to a list. The giver pays on the provider's page; the payment
 * is a destination charge to the owner's payout account (Stripe Connect),
 * the platform keeping its fee: the gift is never the platform's money, and
 * so is no order of its shop.
 *
 *   start()    the contribution, and where to send the giver;
 *   confirm()  on their return (or the webhook): asks the provider, marks
 *              it paid, dispatches ContributionPaidEvent - once.
 */
class Contributions
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly OmnitradeGateways $gateways,
        private readonly ?EventDispatcherInterface $dispatcher = null,
        #[Autowire('%marketplace.wishlist.fee_rate%')] private readonly float $feeRate = 0.03,
        #[Autowire('%marketplace.wishlist.fee_fixed%')] private readonly int $feeFixed = 0,
        #[Autowire('%marketplace.wishlist.minimum%')] private readonly int $minimum = 500,
    ) {
    }

    /** The platform's fee on a gift of that amount, before any listener's word. */
    public function fee(int $amount): int
    {
        return (int) round($amount * $this->feeRate) + $this->feeFixed;
    }

    /**
     * @param array{email?: ?string, message?: ?string, coverFees?: bool, giverReference?: ?string, locale?: ?string} $options
     *
     * @return Contribution pending, its getRedirectUrl() the page to pay on
     *
     * @throws WishlistException wishlist.error.closed, .not_fund, .no_payout, .minimum, .too_much, .payment
     */
    public function start(Item $item, int $amount, string $name, string $returnUrl, ?string $cancelUrl = null, array $options = []): Contribution
    {
        $wishlist = $item->getWishlist();
        if (true !== $wishlist?->isOpen() || $item->isHidden()) {
            throw new WishlistException('wishlist.error.closed');
        }
        if (!$item->getKind()->takesMoney()) {
            throw new WishlistException('wishlist.error.not_fund');
        }
        $account = $wishlist->getPayoutAccount();
        if (!$account?->isReady()) {
            throw new WishlistException('wishlist.error.no_payout');
        }
        if ($amount < $this->minimum) {
            throw new WishlistException('wishlist.error.minimum', ['{minimum}' => $this->minimum / 100]);
        }
        if (WishlistItemKind::SHARE === $item->getKind() && null !== $item->getRemaining() && $amount > $item->getRemaining()) {
            throw new WishlistException('wishlist.error.too_much', ['{left}' => $item->getRemaining() / 100]);
        }
        $bridge = $this->gateways->get($account->getGateway());
        if (null === $bridge) {
            throw new WishlistException('wishlist.error.payment');
        }

        $contribution = new Contribution($item, $amount, $name, $options['email'] ?? null);
        $contribution->setMessage($options['message'] ?? null)->setGiverReference($options['giverReference'] ?? null);
        $event = new ContributionPreparingEvent($contribution, $this->fee($amount), ['contribution' => $contribution->getReference()], sprintf('%s - %s', $wishlist->getTitle(), $item->getTitle()));
        $this->dispatcher?->dispatch($event);
        $covered = ($options['coverFees'] ?? false) && $wishlist->isGiverMayCoverFees();
        $contribution->setFee(min(max(0, $event->fee), $amount), $covered);
        $this->entityManager->persist($contribution);
        $this->entityManager->flush();

        $currency = $contribution->getCurrency();
        try {
            $paid = $bridge->gateway()->purchase(new Payment(
                Money::of($contribution->getCharged(), $currency),
                $contribution->getReference(),
                $event->description,
                new Customer($contribution->getEmail(), $name),
                [new Line((string) $event->description, Money::of($contribution->getCharged(), $currency), 1, physical: false)],
                returnUrl: $returnUrl,
                cancelUrl: $cancelUrl,
                idempotencyKey: $contribution->getReference(),
                metadata: array_map('strval', $event->metadata),
                locale: $options['locale'] ?? null,
                destination: $account->getReference(),
                applicationFee: $contribution->getFee() > 0 ? Money::of($contribution->getFee(), $currency) : null,
            ));
        } catch (OmnitradeException $e) {
            $contribution->markCancelled();
            $this->entityManager->flush();

            throw new WishlistException('wishlist.error.payment', ['{reason}' => $e->getMessage()]);
        }
        $contribution->setProvider($account->getGateway(), '' !== $paid->reference ? $paid->reference : null);
        $contribution->setRedirectUrl($paid->redirectUrl);
        if (Status::PAID === $paid->status) {
            $this->markPaid($contribution);
        } elseif ($paid->status->isFinal()) {
            $contribution->markCancelled();
        }
        $this->entityManager->flush();
        if (null === $contribution->getRedirectUrl() && !$contribution->isPaid()) {
            throw new WishlistException('wishlist.error.payment');
        }

        return $contribution;
    }

    public function find(string $reference): ?Contribution
    {
        return $this->entityManager->getRepository(Contribution::class)->findOneBy(['reference' => $reference]);
    }

    public function findByProviderReference(string $gateway, string $providerReference): ?Contribution
    {
        return $this->entityManager->getRepository(Contribution::class)->findOneBy(['gateway' => $gateway, 'providerReference' => $providerReference]);
    }

    /** Where it stands at the provider, applied: paid, cancelled, or still pending. */
    public function confirm(Contribution $contribution): Contribution
    {
        if (ContributionStatus::PENDING !== $contribution->getStatus() || null === $contribution->getProviderReference()) {
            return $contribution;
        }
        $bridge = $this->gateways->get((string) $contribution->getGateway());
        if (null === $bridge) {
            return $contribution;
        }
        try {
            $paid = $bridge->gateway()->fetch($contribution->getProviderReference());
        } catch (OmnitradeException) {
            return $contribution; // the webhook will say
        }

        return $this->apply($contribution, $paid->status);
    }

    /** A provider's word on it (its return page's fetch, its webhook). */
    public function apply(Contribution $contribution, ?Status $status): Contribution
    {
        if (Status::PAID === $status) {
            $this->markPaid($contribution);
        } elseif (null !== $status && $status->isFinal() && ContributionStatus::PENDING === $contribution->getStatus()) {
            $contribution->markCancelled();
            $this->entityManager->flush();
        }

        return $contribution;
    }

    /** Paid, and told - once: the row is locked and its state read from the database (the return page and the webhook come together). */
    private function markPaid(Contribution $contribution): void
    {
        $this->entityManager->beginTransaction();
        try {
            if (null !== $contribution->getId()) {
                $this->entityManager->lock($contribution, LockMode::PESSIMISTIC_WRITE);
                $stored = $this->entityManager->createQuery('SELECT c.status FROM '.Contribution::class.' c WHERE c.id = :id')->setParameter('id', $contribution->getId())->getSingleScalarResult();
                $stored = $stored instanceof ContributionStatus ? $stored : ContributionStatus::tryFrom((string) $stored);
                if (ContributionStatus::PENDING !== $stored) {
                    $this->entityManager->commit();

                    return;
                }
            }
            $contribution->markPaid();
            $this->entityManager->flush();
            $this->dispatcher?->dispatch(new ContributionPaidEvent($contribution));
            $this->entityManager->flush();
            $this->entityManager->commit();
        } catch (\Throwable $e) {
            $this->entityManager->rollback();

            throw $e;
        }
    }
}
