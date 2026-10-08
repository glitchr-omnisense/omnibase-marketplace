<?php

namespace Base\Marketplace\Security;

use Base\Marketplace\Entity\Invoice;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Who reads an invoice: its buyer (the customer of its order, signed in),
 * and whoever runs the shop's back office (MarketplaceVoter::VIEW).
 *
 * @extends Voter<string, Invoice>
 */
final class InvoiceVoter extends Voter
{
    public const VIEW = 'MARKETPLACE_INVOICE_VIEW';

    public function __construct(private readonly AuthorizationCheckerInterface $authorization)
    {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return self::VIEW === $attribute && $subject instanceof Invoice;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?\Symfony\Component\Security\Core\Authorization\Voter\Vote $vote = null): bool
    {
        $user = $token->getUser();
        if (null !== $user && $subject->getOrder()?->isCustomer($user)) {
            return true;
        }

        return null !== $user && $this->authorization->isGranted(MarketplaceVoter::VIEW);
    }
}
