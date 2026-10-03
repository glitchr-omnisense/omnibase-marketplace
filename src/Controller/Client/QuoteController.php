<?php

namespace Base\Marketplace\Controller\Client;

use Base\Entity\User;
use Base\Marketplace\Entity\Product;
use Base\Marketplace\Entity\Quote;
use Base\Marketplace\Entity\Quote\QuoteLine;
use Base\Marketplace\Enum\QuoteStatus;
use Base\Marketplace\Enum\TradeDirection;
use Base\Marketplace\Form\QuoteRequestType;
use Base\Marketplace\Model\QuoteRequest;
use Base\Marketplace\Pricing\ExportExemption;
use Base\Marketplace\Repository\QuoteRepository;
use Base\Marketplace\Service\CompanyRegistry;
use Base\Marketplace\Service\QuoteStatusGuard;
use Base\Marketplace\Service\QuoteToOrder;
use Base\Marketplace\Service\VatNumbers;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Business quotes on the site: the form asking for one (from a product's
 * page, from a cart that cannot go where it should, from a trade page), the
 * quote read through its own link (/cotation/<token>), accepted - it becomes
 * an order waiting in the client's carts (Service\QuoteToOrder) - or
 * declined, and the client's quotes in their account.
 *
 * "Cotation", not "devis": omnibase/forge keeps /devis for a studio's.
 */
class QuoteController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly QuoteRepository $quotes,
        private readonly TranslatorInterface $translator,
        #[Autowire('%marketplace.quotes.enabled%')] private readonly bool $enabled = true,
        #[Autowire('%marketplace.quotes.recipient%')] private readonly ?string $recipient = null,
    ) {
    }

    #[Route('/cotation', name: 'marketplace_quote_request', methods: ['GET', 'POST'])]
    public function Request(Request $request, MailerInterface $mailer, CompanyRegistry $registry, VatNumbers $vatNumbers): Response
    {
        if (!$this->enabled) {
            throw $this->createNotFoundException('Quotes are off.');
        }

        $data = new QuoteRequest();
        $user = $this->getUser();
        if ($user instanceof User) {
            $data->email = (string) $user->getEmail();
            $data->contactName = trim((string) $user);
        }
        // From a product's page (?product=12), from a cart going abroad (?country=JP).
        $products = array_values(array_filter(array_map(
            fn ($id) => $this->entityManager->getRepository(Product::class)->find((int) $id),
            array_filter((array) ($request->query->all()['product'] ?? []) ?: array_filter([$request->query->get('product')])),
        )));
        $data->products = array_map(fn (Product $p) => $p->getId(), $products);
        if ($country = $request->query->get('country')) {
            $data->country = strtoupper(substr((string) $country, 0, 2));
            $data->direction = ExportExemption::inEu($data->country) ? TradeDirection::DOMESTIC : TradeDirection::EXPORT;
        }
        if ($products && '' === $data->title) {
            $data->title = implode(', ', array_map('strval', $products));
        }

        $form = $this->createForm(QuoteRequestType::class, $data);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $quote = new Quote();
            $quote->setClient($user instanceof User ? $user : null)
                ->setContactName($data->contactName)
                ->setEmail($data->email)
                ->setCompanyName($data->companyName)
                ->setTitle($data->title)
                ->setRequest($data->request)
                ->setDirection($data->direction)
                ->setIncoterm($data->incoterm)
                ->setCountry($data->country)
                ->setPlace($data->place)
                ->setVolume($data->volume)
                ->setTargetDate($data->targetDate)
                ->setDeliveryAddress($data->deliveryAddress)
                ->setStatus(QuoteStatus::REQUESTED);
            // A French business: its SIRET, and what the State's register says of it.
            if ($data->siret) {
                $quote->setSiret($data->siret)->setCompanyCheck($registry->lookup($data->siret));
            } elseif ($data->vatNumber) {
                // An EU business: its VAT number, and what VIES says of it.
                $check = $vatNumbers->check($data->vatNumber);
                $quote->setVatNumber($check['number'] ?? $data->vatNumber)->setCompanyRecord([
                    'status' => $check['status'],
                    'source' => 'vies',
                    'name' => $check['check']?->name ?? $data->companyName,
                    'address' => $check['check']?->address ?? null,
                    'checked_at' => (new \DateTimeImmutable())->format(\DATE_ATOM),
                ]);
            }
            foreach ($products as $product) {
                $quote->addLine(new QuoteLine($product, 1, (int) $product->getUnitPrice() * $product->getPackSize(), $product->getPackSize()));
            }
            $this->quotes->saveNumbered($quote);

            if ($this->recipient) {
                $mailer->send((new TemplatedEmail())
                    ->to($this->recipient)
                    ->replyTo(new Address($quote->getEmail(), $quote->getContactName()))
                    ->subject(sprintf('[%s] %s — %s', $this->translator->trans('@marketplace.quote.mail.requested_tag'), $quote->getReference(), $quote->getTitle()))
                    ->htmlTemplate('@Marketplace/email/quote_requested.html.twig')
                    ->context(['quote' => $quote]));
            }

            return $this->render('@Marketplace/client/quote/requested.html.twig', ['quote' => $quote]);
        }

        return $this->render('@Marketplace/client/quote/request.html.twig', [
            'form' => $form,
            'products' => $products,
        ], new Response(null, $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    #[Route('/cotation/{token}', name: 'marketplace_quote', requirements: ['token' => '[A-Za-z0-9_\-]{43}'])]
    public function Show(string $token, QuoteToOrder $quoteToOrder): Response
    {
        $quote = $this->find($token);

        return $this->render('@Marketplace/client/quote/show.html.twig', [
            'quote' => $quote,
            'items' => $quoteToOrder->itemsOf($quote),
            'awaiting_payment' => QuoteStatusGuard::isAwaitingPayment($quote),
            'export' => $quote->getCountry() && !ExportExemption::inEu($quote->getCountry()),
        ]);
    }

    #[Route('/cotation/{token}/accepter', name: 'marketplace_quote_accept', requirements: ['token' => '[A-Za-z0-9_\-]{43}'], methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function Accept(Request $request, string $token, QuoteToOrder $quoteToOrder): Response
    {
        $quote = $this->find($token);
        $this->assertMine($request, $quote);

        try {
            $order = $quoteToOrder->accept($quote, $this->getUser());
        } catch (\DomainException $e) {
            $this->addFlash('error', $this->translator->trans('@marketplace.'.$e->getMessage()));

            return $this->redirectToRoute('marketplace_quote', ['token' => $token]);
        }

        $this->addFlash('success', $this->translator->trans('@marketplace.quote.accepted', ['{reference}' => $quote->getReference()]));

        return $this->redirectToRoute('marketplace_checkout', ['order' => $order->getId()]);
    }

    #[Route('/cotation/{token}/refuser', name: 'marketplace_quote_decline', requirements: ['token' => '[A-Za-z0-9_\-]{43}'], methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function Decline(Request $request, string $token): Response
    {
        $quote = $this->find($token);
        $this->assertMine($request, $quote);
        if ($quote->getStatus()->isOpenToClient()) {
            $quote->setStatus(QuoteStatus::DECLINED);
            $this->entityManager->flush();
            $this->addFlash('info', $this->translator->trans('@marketplace.quote.declined', ['{reference}' => $quote->getReference()]));
        }

        return $this->redirectToRoute('marketplace_quotes');
    }

    #[Route('/mes-cotations', name: 'marketplace_quotes')]
    #[IsGranted('ROLE_USER')]
    public function Mine(): Response
    {
        $quotes = array_values(array_filter(
            $this->quotes->findForClient($this->getUser()),
            fn (Quote $quote) => !\in_array($quote->getStatus(), [QuoteStatus::DRAFT], true),
        ));

        return $this->render('@Marketplace/client/quote/index.html.twig', ['quotes' => $quotes]);
    }

    private function find(string $token): Quote
    {
        $quote = $this->quotes->findOneBy(['token' => $token]);
        if (!$quote instanceof Quote || \in_array($quote->getStatus(), [QuoteStatus::REQUESTED, QuoteStatus::DRAFT], true)) {
            throw $this->createNotFoundException('No such quote.');
        }

        return $quote;
    }

    private function assertMine(Request $request, Quote $quote): void
    {
        if (!$this->isCsrfTokenValid('marketplace_quote_'.$quote->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid token.');
        }
        $user = $this->getUser();
        $mine = $quote->getClient() ? $quote->getClient()->getId() === $user?->getId() : 0 === strcasecmp($quote->getEmail(), (string) $user?->getEmail());
        if (!$mine) {
            throw $this->createAccessDeniedException('This quote was made for someone else.');
        }
    }
}
