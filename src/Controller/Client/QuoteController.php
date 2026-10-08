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
use Base\Marketplace\Quote\Signature\QuoteSignatures;
use Base\Marketplace\Security\MarketplaceVoter;
use Base\Marketplace\Repository\QuoteRepository;
use Base\Marketplace\Service\Attachments;
use Base\Marketplace\Service\CompanyRegistry;
use Base\Marketplace\Service\QuoteStatusGuard;
use Base\Marketplace\Service\QuoteToOrder;
use Base\Marketplace\Service\VatNumbers;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
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
 * With glitchr/omnisign and marketplace.quotes.signature, accepting a priced
 * quote is signing it in the page (Quote\Signature\QuoteSignatures): the
 * client goes to the provider's signing page and comes back to
 * /cotation/<token>/signee, the quote accepted once the signature is
 * completed; the signed quote and its evidence are downloaded from its page.
 *
 * Under /cotation by default ("cotation", not "devis": omnibase/forge keeps
 * /devis for a studio's); marketplace.quotes.path moves it - "devis" on a
 * site without the forge.
 */
class QuoteController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly QuoteRepository $quotes,
        private readonly TranslatorInterface $translator,
        #[Autowire('%marketplace.quotes.enabled%')] private readonly bool $enabled = true,
        #[Autowire('%marketplace.quotes.recipient%')] private readonly ?string $recipient = null,
        #[Autowire('%marketplace.quotes.phone%')] private readonly bool $phone = true,
        #[Autowire('%marketplace.quotes.attachments%')] private readonly bool $attachments = true,
        #[Autowire('%marketplace.quotes.consent%')] private readonly bool $consent = false,
        private readonly ?QuoteSignatures $signatures = null,
    ) {
    }

    #[Route('/%marketplace.quotes.path%', name: 'marketplace_quote_request', methods: ['GET', 'POST'])]
    public function Request(Request $request, MailerInterface $mailer, CompanyRegistry $registry, VatNumbers $vatNumbers, Attachments $files): Response
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

        $form = $this->createForm(QuoteRequestType::class, $data, [
            'phone' => $this->phone,
            'attachments' => $this->attachments,
            'privacy_consent' => $this->consent,
        ]);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid() && $this->attachments && ($refusal = $files->refusal($data->files))) {
            // Too many, too heavy, or not a kind the shop takes: said on the field, nothing kept.
            $form->get('files')->addError(new FormError($this->translator->trans('@marketplace.'.$refusal[0], $refusal[1])));
        }
        if ($form->isSubmitted() && $form->isValid()) {
            if ($form->has('website') && '' !== trim((string) $form->get('website')->getData())) {
                // A glitchr/omnibase from before the forms' guard: the form's own trap was filled - thanked
                // like anybody, nothing stored, nobody told.
                return $this->render('@Marketplace/client/quote/requested.html.twig', ['quote' => (new Quote('—'))->setTitle($data->title)->setEmail($data->email)]);
            }
            $quote = new Quote();
            $quote->setClient($user instanceof User ? $user : null)
                ->setContactName($data->contactName)
                ->setEmail($data->email)
                ->setPhone($data->phone)
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
            if ($this->attachments && $data->files) {
                $files->attachToQuote($quote, $data->files);
                $this->entityManager->flush();
            }

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

    #[Route('/%marketplace.quotes.path%/{token}', name: 'marketplace_quote', requirements: ['token' => '[A-Za-z0-9_\-]{43}'])]
    public function Show(string $token, QuoteToOrder $quoteToOrder): Response
    {
        $quote = $this->find($token);

        return $this->render('@Marketplace/client/quote/show.html.twig', [
            'quote' => $quote,
            'items' => $quoteToOrder->itemsOf($quote),
            'awaiting_payment' => QuoteStatusGuard::isAwaitingPayment($quote),
            'export' => $quote->getCountry() && !ExportExemption::inEu($quote->getCountry()),
            // Accepting it is signing it (glitchr/omnisign), and where its latest signature stands.
            'signing' => $this->signing(),
            'signature' => $this->signatures?->latest($quote),
        ]);
    }

    #[Route('/%marketplace.quotes.path%/{token}/accepter', name: 'marketplace_quote_accept', requirements: ['token' => '[A-Za-z0-9_\-]{43}'], methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function Accept(Request $request, string $token, QuoteToOrder $quoteToOrder): Response
    {
        $quote = $this->find($token);
        $this->assertMine($request, $quote);

        // Signed in the page first: accepted once the provider says the signature is completed (Signed()).
        if ($this->signing() && $quote->isAcceptable()) {
            try {
                return $this->redirect($this->signatures->start($quote, $this->generateUrl('marketplace_quote_signed', ['token' => $token], UrlGeneratorInterface::ABSOLUTE_URL)));
            } catch (\DomainException $e) {
                $this->addFlash('error', $this->translator->trans('@marketplace.'.$e->getMessage()));
            } catch (\Throwable $e) {
                // The provider refused or did not answer: said, nothing accepted.
                $this->addFlash('error', $this->translator->trans('@marketplace.quote.signature.unavailable'));
            }

            return $this->redirectToRoute('marketplace_quote', ['token' => $token]);
        }

        try {
            $order = $quoteToOrder->accept($quote, $this->getUser());
        } catch (\DomainException $e) {
            $this->addFlash('error', $this->translator->trans('@marketplace.'.$e->getMessage()));

            return $this->redirectToRoute('marketplace_quote', ['token' => $token]);
        }

        $this->addFlash('success', $this->translator->trans('@marketplace.quote.accepted', ['{reference}' => $quote->getReference()]));

        return $this->redirectToRoute('marketplace_checkout', ['order' => $order->getId()]);
    }

    /** Back from the provider's signing page: the quote accepted once signed, open again when declined or expired. */
    #[Route('/%marketplace.quotes.path%/{token}/signee', name: 'marketplace_quote_signed', requirements: ['token' => '[A-Za-z0-9_\-]{43}'], methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function Signed(string $token): Response
    {
        $quote = $this->find($token);
        if (null === $this->signatures || !$this->isMine($quote)) {
            throw $this->createNotFoundException('No signature for this quote.');
        }
        try {
            $back = $this->signatures->back($quote, $this->getUser());
        } catch (\DomainException $e) {
            $this->addFlash('error', $this->translator->trans('@marketplace.'.$e->getMessage()));

            return $this->redirectToRoute('marketplace_quote', ['token' => $token]);
        }
        if (null !== $back['order']) {
            $this->addFlash('success', $this->translator->trans('@marketplace.quote.signature.signed', ['{reference}' => $quote->getReference()]));

            return $this->redirectToRoute('marketplace_checkout', ['order' => $back['order']->getId()]);
        }
        $this->addFlash('completed' === $back['status'] ? 'success' : 'info', $this->translator->trans('@marketplace.quote.signature.'.$back['status'], ['{reference}' => $quote->getReference()]));

        return $this->redirectToRoute('marketplace_quote', ['token' => $token]);
    }

    /** The signed quote ("document") or its evidence ("preuve"): for its client and the shop's staff. */
    #[Route('/%marketplace.quotes.path%/{token}/signature/{kind}', name: 'marketplace_quote_signature_file', requirements: ['token' => '[A-Za-z0-9_\-]{43}', 'kind' => 'document|preuve'], methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function SignatureFile(string $token, string $kind): Response
    {
        $quote = $this->find($token);
        if (!$this->isMine($quote) && !$this->isGranted(MarketplaceVoter::VIEW)) {
            throw $this->createAccessDeniedException('This quote was made for someone else.');
        }
        $content = $this->signatures?->file($quote, 'preuve' === $kind ? 'evidence' : 'document');
        if (null === $content) {
            throw $this->createNotFoundException('Not signed yet.');
        }
        $name = sprintf('%s-%s.pdf', 'preuve' === $kind ? 'preuve-signature' : 'cotation-signee', $quote->getReference());
        $response = new Response($content, 200, ['Content-Type' => 'application/pdf', 'X-Content-Type-Options' => 'nosniff']);
        $response->headers->set('Content-Disposition', HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $name, preg_replace('/[^A-Za-z0-9._-]+/', '_', $name)));
        $response->setPrivate();

        return $response;
    }

    #[Route('/%marketplace.quotes.path%/{token}/refuser', name: 'marketplace_quote_decline', requirements: ['token' => '[A-Za-z0-9_\-]{43}'], methods: ['POST'])]
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
        if (!$this->isMine($quote)) {
            throw $this->createAccessDeniedException('This quote was made for someone else.');
        }
    }

    private function isMine(Quote $quote): bool
    {
        $user = $this->getUser();

        return $quote->getClient() ? $quote->getClient()->getId() === $user?->getId() : 0 === strcasecmp($quote->getEmail(), (string) $user?->getEmail());
    }

    /** Whether accepting a quote is signing it: glitchr/omnisign, and marketplace.quotes.signature. */
    private function signing(): bool
    {
        return null !== $this->signatures && $this->signatures->isEnabled();
    }
}
