<?php

namespace Base\Marketplace\Security;

use Base\Marketplace\Entity\Product;
use Base\Marketplace\Entity\Product\Taxon;
use Base\Marketplace\Entity\Store;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\AccessDecisionManagerInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Who reaches the marketplace in the back office:
 *
 * - MARKETPLACE_MANAGE (change anything): the creators, `marketplace.admin_role`
 *   (ROLE_SUPERADMIN by default; ROLE_EDITOR inherits it).
 * - MARKETPLACE_VIEW (read every marketplace screen): the creators, and the
 *   owners of a shop - a member among a Store's owners.
 * - MARKETPLACE_PRICING (change VAT, taxes, prices, discounts, a store's VAT
 *   regime): the creators, and the shops' owners - their business, their
 *   prices, and theirs only: asked about a record, an owner is granted it
 *   when it belongs to one of their stores (a product, a store, a tax, fee
 *   or discount scoped to it). What every shop shares - a region's VAT, the
 *   exchange rates - stays the creators'.
 *
 * Nobody else, whatever other back-office rights they hold. The marketplace's
 * CRUDs carry MARKETPLACE_VIEW as their entity permission (MarketplaceAdminTrait);
 * their changes already require ROLE_SUPERADMIN, the admin's default for
 * new, edit and delete.
 */
final class MarketplaceVoter extends Voter implements ResetInterface
{
    public const VIEW = 'MARKETPLACE_VIEW';
    public const MANAGE = 'MARKETPLACE_MANAGE';
    public const PRICING = 'MARKETPLACE_PRICING';

    /** @var array<int, bool> owner or not, per member, for the request */
    private array $owners = [];

    public function __construct(
        private readonly AccessDecisionManagerInterface $accessDecisionManager,
        private readonly EntityManagerInterface $entityManager,
        #[Autowire('%marketplace.admin_role%')] private readonly string $creators = 'ROLE_SUPERADMIN',
    ) {
    }

    /** Between requests (a worker): ownership may have changed. */
    public function reset(): void
    {
        $this->owners = [];
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return \in_array($attribute, [self::VIEW, self::MANAGE, self::PRICING], true);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        if ($this->accessDecisionManager->decide($token, [$this->creators])) {
            return true;
        }
        if (self::MANAGE === $attribute) {
            return false;
        }

        $user = $token->getUser();
        $id = \is_object($user) && method_exists($user, 'getId') ? $user->getId() : null;
        if (null === $id) {
            return false;
        }

        // A record: its own stores' owners only.
        if (self::PRICING === $attribute && \is_object($subject)) {
            foreach ($this->storesOf($subject) as $store) {
                if ($store->getOwners()->exists(fn ($key, $owner) => $owner->getId() === $id)) {
                    return true;
                }
            }

            return false;
        }

        return $this->owners[$id] ??= (bool) $this->entityManager->createQueryBuilder()
            ->select('COUNT(s.id)')->from(Store::class, 's')
            ->join('s.owners', 'o')->where('o.id = :member')->setParameter('member', $id)
            ->getQuery()->getSingleScalarResult();
    }

    /**
     * The stores a record belongs to: a store itself, a product's, and for a
     * tax, a fee or a discount those its scopes name (a store, a product's,
     * a taxon's). None - a region's VAT, an exchange rate - means every
     * shop's.
     *
     * @return Store[]
     */
    private function storesOf(object $subject): array
    {
        if ($subject instanceof Store) {
            return [$subject];
        }
        if ($subject instanceof Product || $subject instanceof Taxon) {
            return array_filter([$subject->getStore()]);
        }
        if (!method_exists($subject, 'getScopes')) {
            return [];
        }

        $stores = [];
        foreach ($subject->getScopes() as $scope) {
            $values = $scope->getValue();
            foreach (\is_array($values) ? $values : [$values] as $value) {
                if (is_numeric($value) && $scope->getAdapter() instanceof \Base\Marketplace\Entity\Sales\Attribute\Scope\StoreAdapter) {
                    $value = $this->entityManager->find(Store::class, $value);
                }
                $store = match (true) {
                    $value instanceof Store => $value,
                    $value instanceof Product, $value instanceof Taxon => $value->getStore(),
                    default => null,
                };
                if ($store instanceof Store) {
                    $stores[] = $store;
                }
            }
        }

        return $stores;
    }
}
