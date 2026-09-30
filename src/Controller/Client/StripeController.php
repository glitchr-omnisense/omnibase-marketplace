<?php

namespace Base\Marketplace\Controller\Client;

use Base\Marketplace\Entity\Order;
use Base\Marketplace\Entity\Order\Method\PaymentMethod;
use Base\Marketplace\Entity\Order\Transaction;
use Base\Marketplace\Payment\StripeGateway;
use Base\Marketplace\Service\Checkout;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Where Stripe sends people and events back. Both ends settle the same
 * transaction, found by its Checkout session id; Checkout::confirm() is
 * idempotent, so the return page and the webhook may both arrive.
 */
class StripeController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly Checkout $checkout,
        private readonly TranslatorInterface $translator,
        private readonly ?StripeGateway $stripe = null,
    ) {
    }

    #[Route('/panier/{order}/stripe', name: 'marketplace_stripe_return', requirements: ['order' => '\d+'])]
    public function Return(Request $request, int $order): Response
    {
        $order = $this->entityManager->getRepository(Order::class)->find($order);
        $transaction = $order ? $this->pending($order) : null;
        if (!$this->stripe || !$order || !$transaction || !$order->isCustomer($this->getUser())) {
            return $this->redirectToRoute('marketplace_cart');
        }

        if ($request->query->getBoolean('cancel')) {
            $cancelled = $this->checkout->cancel($order, $transaction);
            if ($cancelled->getResponse()) {
                return $cancelled->getResponse();
            }
            $this->addFlash('error', $this->translator->trans('@marketplace.stripe.cancelled'));

            return $this->redirectToRoute('marketplace_checkout', ['order' => $order->getId()]);
        }

        $session = $this->stripe->session($order->getPaymentMethod(), (string) $transaction->getWebhook());
        if ('paid' === ($session['payment_status'] ?? null)) {
            $this->checkout->confirm($order, $transaction);
            $this->addFlash('success', $this->translator->trans('@marketplace.checkout.paid', ['{reference}' => (string) $order->getReference()]));
        } else {
            $this->addFlash('info', $this->translator->trans('@marketplace.stripe.pending', ['{reference}' => (string) $order->getReference()]));
        }

        return $this->redirectToRoute('marketplace_order', ['reference' => $order->getReference()]);
    }

    #[Route('/marketplace/stripe/webhook', name: 'marketplace_stripe_webhook', methods: ['POST'])]
    public function Webhook(Request $request): JsonResponse
    {
        $payload = $request->getContent();
        $event = json_decode($payload, true);
        $sessionId = $event['data']['object']['id'] ?? null;
        $transaction = $sessionId ? $this->entityManager->getRepository(Transaction::class)->findOneBy(['webhook' => $sessionId]) : null;
        $order = $transaction?->getOrder();
        $method = $order?->getPaymentMethod();

        if (!$this->stripe || !$transaction || !$order || !$method instanceof PaymentMethod) {
            return new JsonResponse(['ignored' => true]);
        }
        if (!$this->stripe->verify($method, $payload, (string) $request->headers->get('Stripe-Signature'))) {
            return new JsonResponse(['error' => 'Invalid signature.'], 400);
        }

        switch ($event['type'] ?? null) {
            case 'checkout.session.completed':
            case 'checkout.session.async_payment_succeeded':
                if ('paid' === ($event['data']['object']['payment_status'] ?? null)) {
                    $this->checkout->confirm($order, $transaction);
                }
                break;
            case 'checkout.session.expired':
            case 'checkout.session.async_payment_failed':
                // Only the session the order is being paid with now: an older
                // one expiring must not send back to the cart an order paid,
                // or being paid, through a newer one.
                if ($order->isPending() && $this->pending($order) === $transaction) {
                    $this->checkout->cancel($order, $transaction);
                }
                break;
        }

        return new JsonResponse(['received' => $event['type'] ?? null]);
    }

    private function pending(Order $order): ?Transaction
    {
        foreach (array_reverse($order->getTransactions()->toArray()) as $transaction) {
            if ($transaction->getWebhook()) {
                return $transaction;
            }
        }

        return null;
    }
}
