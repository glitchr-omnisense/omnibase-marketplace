<?php

namespace Base\Marketplace\Wishlist;

use Base\Entity\User;
use Base\Marketplace\Entity\Wishlist\PayoutAccount;
use Base\Marketplace\Payment\Omnitrade\OmnitradeGateways;
use Doctrine\ORM\EntityManagerInterface;
use Omnitrade\GatewayInterface;
use Omnitrade\Model\Account;
use Omnitrade\Request\CreateAccount;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The connected accounts contributions are paid to: one per owner, opened
 * at the provider named by marketplace.wishlist.payout_gateway (an Express
 * account at Stripe), completed by its holder on the provider's own page,
 * kept up to date from its webhook (account.updated) or on demand.
 */
class PayoutAccounts
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly OmnitradeGateways $gateways,
        #[Autowire('%marketplace.wishlist.payout_gateway%')] private readonly string $gateway = 'stripe',
    ) {
    }

    /** Whether the provider is configured and opens accounts at all. */
    public function isAvailable(): bool
    {
        return true === $this->gateways->get($this->gateway)?->gateway()->supports(CreateAccount::class);
    }

    public function for(User $owner): ?PayoutAccount
    {
        return $this->entityManager->getRepository(PayoutAccount::class)->findOneBy(['owner' => $owner, 'gateway' => $this->gateway]);
    }

    /** The owner's account, opened at the provider when they have none yet. */
    public function open(User $owner, string $country = 'FR'): PayoutAccount
    {
        if ($account = $this->for($owner)) {
            return $account;
        }
        $remote = $this->provider()->createAccount($country, $owner->getEmail(), metadata: ['owner' => (string) $owner->getId()]);
        $account = new PayoutAccount($owner, $this->gateway, $remote->reference, $country);
        $this->apply($account, $remote);
        $this->entityManager->persist($account);
        $this->entityManager->flush();

        return $account;
    }

    /** The provider's page where its holder gives their identity and bank account: valid a few minutes, used once. */
    public function onboardingUrl(PayoutAccount $account, string $returnUrl, string $refreshUrl): string
    {
        return $this->provider($account)->accountLink($account->getReference(), $returnUrl, $refreshUrl);
    }

    /** Asks the provider where it stands (on the holder's return). */
    public function refresh(PayoutAccount $account): PayoutAccount
    {
        $this->apply($account, $this->provider($account)->fetchAccount($account->getReference()));
        $this->entityManager->flush();

        return $account;
    }

    /** The provider's account, as a webhook carries it: applied to ours when we know it. */
    public function applyRemote(string $gateway, Account $remote): ?PayoutAccount
    {
        $account = $this->entityManager->getRepository(PayoutAccount::class)->findOneBy(['gateway' => $gateway, 'reference' => $remote->reference]);
        if ($account) {
            $this->apply($account, $remote);
            $this->entityManager->flush();
        }

        return $account;
    }

    private function apply(PayoutAccount $account, Account $remote): void
    {
        $account->update($remote->detailsSubmitted, $remote->chargesEnabled, $remote->payoutsEnabled, $remote->requirements);
    }

    private function provider(?PayoutAccount $account = null): GatewayInterface
    {
        $bridge = $this->gateways->get($account?->getGateway() ?? $this->gateway);
        if (null === $bridge) {
            throw new WishlistException('wishlist.error.no_payout');
        }

        return $bridge->gateway();
    }
}
