<?php

namespace Base\Marketplace\Controller\Client;

use Base\Marketplace\Entity\Order;
use Base\Marketplace\Entity\Order\Method\PaymentMethod;
use Base\Marketplace\Entity\Order\Transaction;
use Base\Marketplace\Payment\Omnitrade\OmnitradeGateway;
use Base\Marketplace\Payment\PaymentGatewayRegistry;
use Base\Marketplace\Service\Checkout;
use Doctrine\ORM\EntityManagerInterface;
use Omnitrade\Exception\InvalidNotificationException;
use Omnitrade\Exception\OmnitradeException;
use Omnitrade\Model\Status;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Where a payment provider sends people and events back, for every
 * omnitrade gateway: the buyer's return page and the webhook both settle
 * the same transaction, found by the provider's reference; Checkout::confirm()
 * is idempotent, so both may arrive.
 */
class PaymentController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly Checkout $checkout,
        private readonly PaymentGatewayRegistry $gateways,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('/panier/{order}/paiement/{gateway}', name: 'marketplace_payment_return', requirements: ['order' => '\d+'])]
    public function Return(Request $request, int $order, string $gateway): Response
    {
        $bridge = $this->gateways->get($gateway);
        $order = $this->entityManager->getRepository(Order::class)->find($order);
        $transaction = $order ? $this->pending($order) : null;
        if (!$bridge instanceof OmnitradeGateway || !$order || !$transaction || !$order->isCustomer($this->getUser())) {
            return $this->redirectToRoute('marketplace_cart');
        }

        if ($request->query->getBoolean('cancel')) {
            $cancelled = $this->checkout->cancel($order, $transaction);
            if ($cancelled->getResponse()) {
                return $cancelled->getResponse();
            }
            $this->addFlash('error', $this->translator->trans('@marketplace.payment.cancelled'));

            return $this->redirectToRoute('marketplace_checkout', ['order' => $order->getId()]);
        }

        try {
            $paid = $bridge->fetch($transaction);
        } catch (OmnitradeException $e) {
            $this->addFlash('info', $this->translator->trans('@marketplace.payment.pending', ['{reference}' => (string) $order->getReference()]));

            return $this->redirectToRoute('marketplace_order', ['reference' => $order->getReference()]);
        }

        if (Status::PAID === $paid->status) {
            $this->checkout->confirm($order, $transaction);
            $this->addFlash('success', $this->translator->trans('@marketplace.checkout.paid', ['{reference}' => (string) $order->getReference()]));
        } elseif ($paid->status->isFinal()) {
            $this->checkout->cancel($order, $transaction);
            $this->addFlash('error', $this->translator->trans('@marketplace.payment.refused', ['{reason}' => (string) $paid->message]));

            return $this->redirectToRoute('marketplace_checkout', ['order' => $order->getId()]);
        } else {
            $this->addFlash('info', $this->translator->trans('@marketplace.payment.pending', ['{reference}' => (string) $order->getReference()]));
        }

        return $this->redirectToRoute('marketplace_order', ['reference' => $order->getReference()]);
    }

    #[Route('/marketplace/{gateway}/webhook', name: 'marketplace_payment_webhook', methods: ['POST'])]
    public function Webhook(Request $request, string $gateway): JsonResponse
    {
        $bridge = $this->gateways->get($gateway);
        if (!$bridge instanceof OmnitradeGateway) {
            return new JsonResponse(['ignored' => true]);
        }
        try {
            $notification = $bridge->notify($request->getContent(), $request->headers->all());
        } catch (InvalidNotificationException $e) {
            return new JsonResponse(['error' => 'Invalid signature.'], 400);
        }

        $transaction = $notification->reference ? $this->entityManager->getRepository(Transaction::class)->findOneBy(['webhook' => $notification->reference]) : null;
        $order = $transaction?->getOrder();
        if (!$transaction || !$order || !$order->getPaymentMethod() instanceof PaymentMethod) {
            return new JsonResponse(['ignored' => true, 'event' => $notification->event]);
        }

        switch ($notification->status) {
            case Status::PAID:
                $this->checkout->confirm($order, $transaction);
                break;
            case Status::CANCELLED:
            case Status::EXPIRED:
            case Status::REFUSED:
                // Only the payment the order is being paid with now: an older
                // one expiring must not send back to the cart an order paid,
                // or being paid, through a newer one.
                if ($order->isPending() && $this->pending($order) === $transaction) {
                    $this->checkout->cancel($order, $transaction);
                }
                break;
        }

        return new JsonResponse(['received' => $notification->event]);
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
