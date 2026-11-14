<?php

namespace Base\Marketplace\EventListener;

use Base\Marketplace\Event\OrderPaidEvent;
use Base\Marketplace\Service\Consents;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * An order paid whose buyer made statements before paying (Service\Consents) is confirmed to
 * them by e-mail: what they bought, each statement word for word with its date, and the terms of
 * sale they accepted (their link and version) - the confirmation on a durable medium the
 * Code de la consommation asks for (L221-13), the waiver of the right of withdrawal for digital
 * content included (L221-28 13°). marketplace.consent.confirmation_email: false leaves it to the
 * host (@Marketplace/email/_consents.html.twig lists the statements for its own e-mail).
 *
 * Last of the order's listeners (the invoice's is at -100): what failed before it is no reason to
 * write; what fails here (no mailer, no address, a transport down) does not undo the payment - it
 * is said in the log.
 */
final class OrderConfirmationListener
{
    public function __construct(
        private readonly Consents $consents,
        private readonly UrlGeneratorInterface $router,
        private readonly ?MailerInterface $mailer = null,
        private readonly ?TranslatorInterface $translator = null,
        private readonly ?LoggerInterface $logger = null,
        #[Autowire('%marketplace.consent.confirmation_email%')] private readonly bool $enabled = true,
    ) {
    }

    #[AsEventListener(event: OrderPaidEvent::class, priority: -200)]
    public function __invoke(OrderPaidEvent $event): void
    {
        $order = $event->order;
        if (!$this->enabled || [] === ($consents = $this->consents->of($order))) {
            return;
        }
        $to = $order->getCustomer()?->getEmail();
        if (null === $this->mailer || null === $to || '' === $to) {
            $this->logger?->error('The order {order} carries statements made before paying, but its confirmation cannot be sent: {why}.', ['order' => $order->getReference(), 'why' => null === $this->mailer ? 'no symfony/mailer' : 'its buyer has no e-mail address']);

            return;
        }
        try {
            $this->mailer->send((new TemplatedEmail())
                ->to($to)
                ->subject($this->translator?->trans('@marketplace.consent.mail.subject', ['{reference}' => (string) $order->getReference()]) ?? (string) $order->getReference())
                ->htmlTemplate('@Marketplace/email/order_confirmation.html.twig')
                ->context([
                    'order' => $order,
                    'consents' => $consents,
                    'terms_version' => $this->consents->termsVersion(),
                    'link' => $this->router->generate('marketplace_order', ['reference' => $order->getReference()], UrlGeneratorInterface::ABSOLUTE_URL),
                ]));
        } catch (\Throwable $e) {
            $this->logger?->error('The confirmation of the order {order} could not be sent: {error}', ['order' => $order->getReference(), 'error' => $e->getMessage()]);
        }
    }
}
