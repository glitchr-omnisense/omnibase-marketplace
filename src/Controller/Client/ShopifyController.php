<?php

namespace Base\Market\Controller\Client;

use Base\Market\Entity\Order;
use Base\Market\Entity\Order\Transaction;
use Base\Market\Shopify\Api\Endpoint;
use Base\Market\Shopify\Api\Hmac;
use Base\Market\Shopify\Checkout\OrderReconciler;
use Base\Market\Shopify\Webhook\ReplayGuard;
use Base\Market\Shopify\Webhook\ShopifyWebhookHandler;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Shopify's two doors into the shop: the webhook it POSTs to, and the page a
 * member lands on when they come back from paying and want an answer.
 *
 * Every Shopify dependency is nullable and defaulted, and every action leaves
 * at once when they are missing. That is the same arrangement StripeController
 * uses and for the same reason: routes are collected by reflecting over this
 * directory, entirely independently of the container, so these two routes
 * exist in an application that has the integration switched off. They 404
 * there, which is the honest answer.
 */
class ShopifyController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly TranslatorInterface $translator,
        private readonly ?ShopifyWebhookHandler $handler = null,
        private readonly ?ReplayGuard $replayGuard = null,
        private readonly ?Endpoint $endpoint = null,
        private readonly ?OrderReconciler $reconciler = null,
    ) {
    }

    /**
     * Shopify calling. Verify, deduplicate, act - and answer 200 to anything
     * we do not recognise, because eight hours of non-2xx costs the
     * subscription.
     */
    #[Route('/market/shopify/webhook', name: 'market_shopify_webhook', methods: ['POST'])]
    public function Webhook(Request $request): JsonResponse
    {
        if (!$this->handler || !$this->endpoint) {
            return new JsonResponse(['error' => 'Shopify is not enabled.'], Response::HTTP_NOT_FOUND);
        }

        // The raw body, before anything decodes it: the signature covers the
        // bytes as sent, and a re-encoded payload will not match.
        $payload = $request->getContent();

        if (!Hmac::verify($payload, $request->headers->get('X-Shopify-Hmac-Sha256'), $this->endpoint->webhookSecret)) {
            return new JsonResponse(['error' => 'Invalid signature.'], Response::HTTP_UNAUTHORIZED);
        }

        // Cheap, and it stops a payload replayed from another store dead.
        $shop = (string) $request->headers->get('X-Shopify-Shop-Domain', '');
        if ('' !== $this->endpoint->shop() && $shop !== $this->endpoint->shop()) {
            return new JsonResponse(['error' => 'Unexpected shop.'], Response::HTTP_UNAUTHORIZED);
        }

        if ($this->replayGuard?->isReplay($request->headers->get('X-Shopify-Webhook-Id'))) {
            return new JsonResponse(['duplicate' => true]);
        }

        $body = json_decode($payload, true);
        if (!\is_array($body)) {
            return new JsonResponse(['error' => 'Malformed payload.'], Response::HTTP_BAD_REQUEST);
        }

        $topic = (string) $request->headers->get('X-Shopify-Topic', '');

        return new JsonResponse($this->handler->handle($topic, $body));
    }

    /**
     * "I have paid - check now."
     *
     * A poll, not a return: a Shopify invoice checkout ends on Shopify's own
     * thank-you page and never redirects back, so this is a page the member
     * reaches from their pending order, not somewhere Shopify sends them.
     */
    #[Route('/panier/{order}/shopify', name: 'market_shopify_check', requirements: ['order' => '\d+'])]
    public function Check(int $order): Response
    {
        $order = $this->entityManager->getRepository(Order::class)->find($order);
        $transaction = $order ? $this->pending($order) : null;

        if (!$this->reconciler || !$order || !$transaction || $order->getCustomer() !== $this->getUser()) {
            return $this->redirectToRoute('market_cart');
        }

        $status = $this->reconciler->reconcile($order, $transaction);

        if ('paid' === $status) {
            $this->addFlash('success', $this->translator->trans('@market.checkout.paid', ['{reference}' => (string) $order->getReference()]));

            return $this->redirectToRoute('market_order', ['reference' => $order->getReference()]);
        }

        if ('cancelled' === $status) {
            $this->addFlash('error', $this->translator->trans('@market.shopify.cancelled'));

            return $this->redirectToRoute('market_cart');
        }

        $this->addFlash('warning', $this->translator->trans('@market.shopify.pending', ['{reference}' => (string) $order->getReference()]));

        return $this->redirectToRoute('market_order', ['reference' => $order->getReference()]);
    }

    /** The transaction this order is waiting on. */
    private function pending(Order $order): ?Transaction
    {
        $found = null;
        foreach ($order->getTransactions() as $transaction) {
            if (!empty($transaction->getDetails()['shopify_draft_order'])) {
                $found = $transaction;
            }
        }

        return $found;
    }
}
