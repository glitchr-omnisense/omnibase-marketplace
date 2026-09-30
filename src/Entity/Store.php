<?php

namespace Base\Marketplace\Entity;

use Base\Marketplace\Entity\Product\Taxon as ProductTaxon;
use Base\Marketplace\Entity\Review\Taxon as ReviewTaxon;
use Base\Marketplace\Entity\Sales\Region;
use Base\Marketplace\Model\MerchantInterface;
use Base\Marketplace\Repository\StoreRepository;
use Base\Database\Attribute\Hierarchify;
use Base\Database\Attribute\Cache;
use Base\Database\Attribute\Alias;
use Base\Database\Attribute\DiscriminatorEntry;
use Base\Database\Entity\Extension\TranslatableTrait;
use Base\Database\Entity\Extension\TranslatableInterface;
use Base\Entity\Thread;
use Base\Service\Model\LinkableInterface;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Base\Marketplace\Validator\VatNumber as KnownVatNumber;
use Omnistate\Identifier\VatNumber;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

#[ORM\Entity(repositoryClass: StoreRepository::class)]
#[\Base\Database\Attribute\Cache(usage: 'NONSTRICT_READ_WRITE', associations: 'ALL')]
#[\Base\Database\Attribute\DiscriminatorEntry]
#[\Base\Database\Attribute\Hierarchify(hierarchy: ['store'], separator: '/')]
class Store extends Thread implements \Base\Database\Entity\Extension\TranslatableInterface, LinkableInterface
{
    use \Base\Database\Entity\Extension\TranslatableTrait;

    public static function __iconizeStatic(): ?array
    {
        return ['fa-solid fa-store'];
    }

    public function __toLink(array $routeParameters = [], int $referenceType = UrlGeneratorInterface::ABSOLUTE_PATH): ?string
    {
        return $this->getRouter()->generate('marketplace_store', array_merge($routeParameters, ['slug' => $this->getSlug()]), $referenceType);
    }

    public function __construct(?MerchantInterface $merchant = null)
    {
        parent::__construct($merchant);
        $this->regions = new ArrayCollection();
        $this->orders = new ArrayCollection();
        $this->reviews = new ArrayCollection();
        $this->products = new ArrayCollection();

        $this->currency = $this->getParameterBag('marketplace.default_currency');

        $this->productTaxa = new ArrayCollection();
        $this->reviewTaxa = new ArrayCollection();
    }

    #[Alias(column: 'children')]
    protected $products;

    public function getProducts(): Collection
    {
        return $this->products->filter(fn ($p) => $p instanceof Product);
    }

    public function addProduct(Product $product): self
    {
        return $this->addChild($product);
    }

    public function removeProduct(Product $product): self
    {
        return $this->removeChild($product);
    }

    #[Alias(column: 'tags')]
    protected $regions;

    public function getRegions(): Collection
    {
        return $this->regions->filter(fn ($p) => $p instanceof Region);
    }

    // $regions is an alias of the tags (a Region is a Tag): add and remove
    // them there. (It went through addChild(), the threads - a Region is no
    // Thread, so a store could not be given a region.)
    public function addRegion(Region $region): self
    {
        return $this->addTag($region);
    }

    public function removeRegion(Region $region): self
    {
        return $this->removeTag($region);
    }

    #[ORM\OneToMany(targetEntity: Order::class, mappedBy: 'store')]
    protected $orders;

    public function getOrders(): Collection
    {
        return $this->orders;
    }

    public function addOrder(Order $order): self
    {
        if (!$this->orders->contains($order)) {
            $this->orders[] = $order;
            $order->setStore($this);
        }

        return $this;
    }

    public function removeOrder(Order $order): self
    {
        if ($this->orders->removeElement($order)) {
            // set the owning side to null (unless already changed)
            if ($order->getStore() === $this) {
                $order->setStore(null);
            }
        }

        return $this;
    }

    #[ORM\OneToMany(targetEntity: Review::class, mappedBy: 'store')]
    protected $reviews;

    public function getReviews(): Collection
    {
        return $this->reviews;
    }

    public function addReview(Review $review): self
    {
        if (!$this->reviews->contains($review)) {
            $this->reviews[] = $review;
            $review->setStore($this);
        }

        return $this;
    }

    public function removeReview(Review $review): self
    {
        if ($this->reviews->removeElement($review)) {
            // set the owning side to null (unless already changed)
            if ($review->getStore() === $this) {
                $review->setStore(null);
            }
        }

        return $this;
    }

    #[ORM\Column(type: 'string', length: 3)]
    protected $currency;

    public function getCurrency(): ?string
    {
        return $this->currency;
    }

    public function setCurrency(string $currency): self
    {
        $this->currency = $currency;

        return $this;
    }

    /**
     * Whether this store charges VAT: its owner's choice, for their business
     * - and only with a valid VAT number. A store that does not sells without
     * VAT, its orders carrying "TVA non applicable, art. 293 B du CGI"
     * (StoreVatRegime).
     */
    #[ORM\Column(type: 'boolean', options: ['default' => true])]
    protected bool $chargesVat = true;

    public function chargesVat(): bool
    {
        return $this->chargesVat;
    }

    public function setChargesVat(bool $chargesVat): self
    {
        $this->chargesVat = $chargesVat;

        return $this;
    }

    /**
     * The store's intra-EU VAT number (FR12345678901), required to charge
     * VAT - and one VIES knows, checked when it is saved (a SIREN gives the
     * French number, with its key).
     */
    #[ORM\Column(type: 'string', length: 32, nullable: true)]
    #[KnownVatNumber]
    protected ?string $vatNumber = null;

    public function getVatNumber(): ?string
    {
        return $this->vatNumber;
    }

    public function setVatNumber(?string $vatNumber): self
    {
        $vatNumber = null === $vatNumber ? null : trim($vatNumber);
        // As VIES writes it; what cannot be read as one stays as typed, for
        // the validation to say so.
        $this->vatNumber = '' === $vatNumber || null === $vatNumber ? null : (VatNumber::normalize($vatNumber) ?? $vatNumber);

        return $this;
    }

    #[Assert\Callback]
    public function validateVat(ExecutionContextInterface $context): void
    {
        // Whether VIES knows the number is #[KnownVatNumber]'s to say.
        if ($this->chargesVat && null === $this->vatNumber) {
            $context->buildViolation('Sans numéro de TVA valide, la TVA ne peut pas être appliquée.')->atPath('vatNumber')->addViolation();
        }
    }

    #[ORM\OneToMany(targetEntity: ProductTaxon::class, mappedBy: 'store')]
    protected $productTaxa;

    public function getProductTaxa(): Collection
    {
        return $this->productTaxa;
    }

    public function addProductTaxon(ProductTaxon $productTaxon): self
    {
        if (!$this->productTaxa->contains($productTaxon)) {
            $this->productTaxa[] = $productTaxon;
            $productTaxon->setStore($this);
        }

        return $this;
    }

    #[ORM\OneToMany(targetEntity: ReviewTaxon::class, mappedBy: 'store')]
    protected $reviewTaxa;

    public function removeProductTaxon(ProductTaxon $productTaxon): self
    {
        if ($this->productTaxa->removeElement($productTaxon)) {
            // set the owning side to null (unless already changed)
            if ($productTaxon->getStore() === $this) {
                $productTaxon->setStore(null);
            }
        }

        return $this;
    }

    public function getReviewTaxa(): Collection
    {
        return $this->reviewTaxa;
    }

    public function addReviewTaxon(ReviewTaxon $reviewTaxon): self
    {
        if (!$this->reviewTaxa->contains($reviewTaxon)) {
            $this->reviewTaxa[] = $reviewTaxon;
            $reviewTaxon->setStore($this);
        }

        return $this;
    }

    public function removeReviewTaxon(ReviewTaxon $reviewTaxon): self
    {
        if ($this->reviewTaxa->removeElement($reviewTaxon)) {
            // set the owning side to null (unless already changed)
            if ($reviewTaxon->getStore() === $this) {
                $reviewTaxon->setStore(null);
            }
        }

        return $this;
    }

    #[ORM\Column(type: 'boolean')]
    // Open unless closed: Cart::add() only refuses a store explicitly closed.
    protected $open = true;

    public function isOpen(): ?bool
    {
        return $this->open;
    }

    public function setOpen(bool $open): self
    {
        $this->open = $open;

        return $this;
    }
}
