<?php

namespace Base\Marketplace\Controller\Client;

use Base\Entity\User;
use Base\Marketplace\Entity\Order;
use Base\Marketplace\Entity\Order\Transaction;
use Base\Marketplace\Entity\Product;
use Base\Marketplace\Event\QuickOrderDoneEvent;
use Base\Marketplace\Payment\PaymentGatewayRegistry;
use Base\Marketplace\Service\CartException;
use Base\Marketplace\Service\Checkout;
use Base\Marketplace\Service\QuickOrder;
use Base\Marketplace\Twig\QuickOrderTwigExtension;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\UriSigner;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * A product bought in one step (Service\QuickOrder): the form of
 * @Marketplace/client/_quick_order.html.twig (Form\QuickOrderType, guarded
 * as glitchr/omnibase guards a form) posts an e-mail address here, the
 * payment follows, and the buyer ends on the order's own page - a signed
 * link, no account.
 *
 * The provider sends the buyer back to marketplace_payment_return, which
 * expects its customer signed in: QuickOrderReturnListener hands the return
 * of a quick order to back() here instead.
 */
class QuickOrderController extends AbstractController
{
    /** Session: the ids of the quick orders this visitor made. */
    public const SESSION = 'marketplace/quick_orders';

    public function __construct(
        private readonly QuickOrder $quickOrder,
        private readonly EntityManagerInterface $entityManager,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('/commande-express/{id}', name: 'marketplace_quick_order', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function Buy(Request $request, int $id, QuickOrderTwigExtension $forms): Response
    {
        $product = $this->entityManager->getRepository(Product::class)->find($id);
        if (!$product instanceof Product || !$this->quickOrder->isEnabled()) {
            throw $this->createNotFoundException();
        }

        $form = $forms->form($product)->handleRequest($request);
        if (!$form->isSubmitted()) {
            throw new BadRequestHttpException('No quick order was sent.');
        }
        // Back where the form was: the page that showed it, on this site.
        $back = (string) $form->get('back')->getData();
        $back = str_starts_with($back, '/') && !str_starts_with($back, '//') ? $back : $this->generateUrl('marketplace_stores');

        // The form's own trap, where the host's core has no guard: sent back, nothing made.
        if ($form->has('website') && '' !== trim((string) $form->get('website')->getData())) {
            return $this->redirect($back);
        }
        if (!$form->isValid()) {
            // The guard's word (a trap, too fast, a captcha not solved), the address's, or the CSRF token's.
            $error = $form->getErrors(true)->current();

            return $this->refuse($error ? $error->getMessage() : '@marketplace.quick.error.email', $back);
        }

        $user = $this->getUser();
        $email = $form->has('email') ? mb_strtolower(trim((string) $form->get('email')->getData())) : '';
        try {
            $payment = $this->quickOrder->buy($product, $email, $user instanceof User ? $user : null);
        } catch (CartException $e) {
            return $this->refuse('@marketplace.'.$e->getMessage(), $back);
        }

        $session = $request->getSession();
        $session->set(self::SESSION, array_merge((array) $session->get(self::SESSION, []), [$payment->order->getId()]));

        if ($payment->result->isRedirect()) {
            return $this->redirect($payment->result->redirectUrl);
        }
        if ($payment->result->isRefused()) {
            return $this->refuse('@marketplace.quick.error.refused', $back);
        }

        // Paid at once, or waiting for the provider's word: the order's page says which.
        return $this->redirect($this->quickOrder->doneUrl($payment->order));
    }

    /** Back from the payment provider (QuickOrderReturnListener routes marketplace_payment_return here for a quick order). */
    public function Back(Request $request, int $order, string $gateway, PaymentGatewayRegistry $gateways, Checkout $checkout): Response
    {
        $order = $this->entityManager->getRepository(Order::class)->find($order);
        if (!$order instanceof Order) {
            throw $this->createNotFoundException();
        }
        $transaction = $this->pending($order);
        $bridge = $gateways->get($gateway);

        if ($transaction && $request->query->getBoolean('cancel')) {
            $checkout->cancel($order, $transaction);

            return $this->refuse('@marketplace.quick.error.cancelled', $this->generateUrl('marketplace_stores'));
        }

        // The provider's webhook may have confirmed it already; otherwise ask now.
        if ($transaction && $bridge && method_exists($bridge, 'fetch') && !$order->isPaid()) {
            try {
                $paid = $bridge->fetch($transaction);
                if ('paid' === strtolower($paid->status->name)) {
                    $checkout->confirm($order, $transaction);
                } elseif ($paid->status->isFinal()) {
                    $checkout->cancel($order, $transaction);

                    return $this->refuse('@marketplace.quick.error.refused', $this->generateUrl('marketplace_stores'));
                }
            } catch (\Throwable) {
                // Not known yet: the order's page waits for the webhook.
            }
        }

        return $this->redirect($this->quickOrder->doneUrl($order));
    }

    #[Route('/commande-express/merci/{reference}', name: 'marketplace_quick_order_done', requirements: ['reference' => '[A-Za-z0-9\-_]+'])]
    public function Done(Request $request, string $reference, UriSigner $signer, EventDispatcherInterface $dispatcher): Response
    {
        // A plain 403: an expired or forged link is not solved by signing in.
        if (!$signer->checkRequest($request)) {
            throw new AccessDeniedHttpException('This link is invalid or has expired.');
        }
        $order = $this->entityManager->getRepository(Order::class)->findOneBy(['reference' => $reference]) ?? throw $this->createNotFoundException();

        $event = new QuickOrderDoneEvent($order);
        $dispatcher->dispatch($event);

        return $event->getResponse() ?? $this->render('@Marketplace/client/quick_done.html.twig', ['order' => $order]);
    }

    /** @param string $message a translation key (@marketplace.quick.error.*) or a message already translated */
    private function refuse(string $message, string $back): Response
    {
        $this->addFlash('error', $this->translator->trans($message));

        return $this->redirect($back);
    }

    /** The transaction a provider is being asked about: the last one that went to one. */
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
