<?php

namespace Base\Marketplace\Controller\Admin\Crud;

use Base\Admin\Attribute\AdminAction;
use Base\Admin\Config\Action;
use Base\Admin\Config\Actions;
use Base\Admin\Config\Crud;
use Base\Field\CollectionField;
use Base\Field\CountryField;
use Base\Field\DateField;
use Base\Field\DateTimeField;
use Base\Field\EmailField;
use Base\Field\IdField;
use Base\Field\IntegerField;
use Base\Field\SelectField;
use Base\Field\TextareaField;
use Base\Field\TextField;
use Base\Marketplace\Controller\Admin\AbstractMarketplaceCrudController;
use Base\Marketplace\Entity\Quote;
use Base\Marketplace\Entity\Store;
use Base\Marketplace\Enum\Incoterm;
use Base\Marketplace\Enum\QuoteStatus;
use Base\Marketplace\Enum\TradeDirection;
use Base\Marketplace\Form\QuoteLineType;
use Base\Marketplace\Repository\QuoteRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Contracts\Service\Attribute\Required;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The quotes asked from the site: price the lines (a product of the
 * catalogue or a free line, by the lot), the terms (which way, the
 * Incoterm and its place, the country), a discount and a date, then
 * "Send" mails the client their link. The pipeline (QuotePipelineController)
 * shows them by status.
 */
class QuoteCrudController extends AbstractMarketplaceCrudController
{
    private MailerInterface $mailer;
    private TranslatorInterface $translator;
    private int $validity = 30;

    #[Required]
    public function setQuoteServices(MailerInterface $mailer, TranslatorInterface $translator, #[Autowire('%marketplace.quotes.validity%')] int $validity = 30): void
    {
        $this->mailer = $mailer;
        $this->translator = $translator;
        $this->validity = $validity;
    }

    /** Prices: the shops' owners write quotes too (MARKETPLACE_PRICING). */
    protected function isPricing(): bool
    {
        return true;
    }

    public static function getEntityFqcn(): string
    {
        return Quote::class;
    }

    public static function getPreferredIcon(): ?string
    {
        return 'fa-solid fa-file-signature';
    }

    public function configureCrud(Crud $crud): Crud
    {
        return parent::configureCrud($crud)->setDefaultSort(['createdAt' => 'DESC']);
    }

    public function configureActions(Actions $actions): Actions
    {
        $send = Action::new('send', '@marketplace.quote.admin.send', 'fa-solid fa-paper-plane')
            ->linkToCrudAction('send')
            ->displayIf(fn (Quote $quote) => $quote->getStatus()->isEditable());

        return parent::configureActions($actions)->add(Actions::PAGE_INDEX, $send)->add(Actions::PAGE_DETAIL, $send)->add(Actions::PAGE_EDIT, $send);
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->onlyOnIndex();
        yield TextField::new('reference', '@marketplace.quote.reference')->setColumns(3)->setDisabled();
        yield TextField::new('title', '@marketplace.quote.title')->setColumns(9);
        yield TextField::new('status', '@marketplace.quote.status')->setColumns(3)
            ->setFormType(EnumType::class)->setFormTypeOptions(['class' => QuoteStatus::class, 'choice_label' => fn (QuoteStatus $s) => '@marketplace.quote.state.'.$s->value])
            ->formatValue(fn ($value) => $value instanceof QuoteStatus ? $this->translator->trans('@marketplace.quote.state.'.$value->value) : $value);
        yield TextField::new('contactName', '@marketplace.quote.contact')->setColumns(3);
        yield EmailField::new('email')->setColumns(3);
        yield TextField::new('companyName', '@marketplace.quote.company')->setColumns(3);
        yield TextField::new('siret', 'SIRET')->setColumns(3)->hideOnIndex();
        yield TextField::new('vatNumber', '@marketplace.quote.vat')->setColumns(3)->hideOnIndex();
        yield TextField::new('companyBadge', '@marketplace.quote.register')->setColumns(6)->setDisabled()->hideOnForm()->hideOnIndex();
        yield TextField::new('direction', '@marketplace.quote.form.direction')->setColumns(3)->hideOnIndex()
            ->setFormType(EnumType::class)->setFormTypeOptions(['class' => TradeDirection::class, 'required' => false, 'placeholder' => '—', 'choice_label' => fn (TradeDirection $d) => '@marketplace.quote.direction.'.$d->value])
            ->formatValue(fn ($value) => $value instanceof TradeDirection ? $value->value : $value);
        yield TextField::new('incoterm', 'Incoterm')->setColumns(2)
            ->setFormType(EnumType::class)->setFormTypeOptions(['class' => Incoterm::class, 'required' => false, 'placeholder' => '—'])
            ->formatValue(fn ($value) => $value instanceof Incoterm ? $value->value : $value);
        yield TextField::new('place', '@marketplace.quote.form.place')->setColumns(3)->hideOnIndex();
        yield CountryField::new('country', '@marketplace.quote.form.country')->setColumns(2);
        yield TextField::new('volume', '@marketplace.quote.form.volume')->setColumns(4)->hideOnIndex();
        yield DateField::new('targetDate', '@marketplace.quote.form.target_date')->setColumns(3)->hideOnIndex();
        yield TextareaField::new('deliveryAddress', '@marketplace.quote.form.delivery')->hideOnIndex();
        yield SelectField::new('store', '@marketplace.quote.store')->setClass(Store::class)->setRequired(false)->setColumns(4)->hideOnIndex();
        yield DateField::new('validUntil', '@marketplace.quote.valid_until')->setColumns(3)->hideOnIndex();
        yield IntegerField::new('discountPercent', '@marketplace.quote.discount')->setColumns(2)->hideOnIndex();
        yield TextareaField::new('request', '@marketplace.quote.request')->hideOnIndex()->setHelp('@marketplace.quote.request_help');
        yield TextareaField::new('message', '@marketplace.quote.message')->hideOnIndex()->setHelp('@marketplace.quote.message_help');
        yield CollectionField::new('lines', '@marketplace.quote.lines')->setEntryType(QuoteLineType::class)->allowAdd()->allowDelete()->hideOnIndex()
            ->setFormTypeOptions(['by_reference' => false]);
        yield IntegerField::new('total', '@marketplace.quote.total_cents')->onlyOnIndex();
        yield DateTimeField::new('createdAt')->onlyOnIndex();
    }

    public function createEntity(string $entityFqcn): object
    {
        return (new Quote())->setStatus(QuoteStatus::DRAFT);
    }

    /** Numbered when saved, not when the form opens: two forms open took the same number. */
    public function persistEntity(EntityManagerInterface $entityManager, object $entity): void
    {
        if ($entity instanceof Quote) {
            $entityManager->getRepository(Quote::class)->saveNumbered($entity);

            return;
        }
        parent::persistEntity($entityManager, $entity);
    }

    /** Sent: the client gets their link by mail, and may accept it until its date. */
    #[AdminAction('/{entityId}/send')]
    public function send(string $entityId): Response
    {
        /** @var Quote $quote */
        $quote = $this->findEntity($entityId);
        if (!$quote->hasSomethingToSell()) {
            $this->addFlash('danger', $this->translator->trans('@marketplace.quote.admin.empty', ['{reference}' => $quote->getReference()]));

            return $this->redirectToIndex();
        }
        $quote->setStatus(QuoteStatus::SENT);
        $quote->setValidUntil($quote->getValidUntil() ?? new \DateTimeImmutable(sprintf('+%d days', $this->validity)));
        $this->entityManager->flush();

        $this->mailer->send((new TemplatedEmail())
            ->to($quote->getEmail())
            ->subject($this->translator->trans('@marketplace.quote.mail.sent_subject', ['{reference}' => $quote->getReference(), '{title}' => $quote->getTitle()]))
            ->htmlTemplate('@Marketplace/email/quote_sent.html.twig')
            ->context(['quote' => $quote]));
        $this->addFlash('success', $this->translator->trans('@marketplace.quote.admin.sent', ['{reference}' => $quote->getReference(), '{email}' => $quote->getEmail()]));

        return $this->redirectToIndex();
    }
}
