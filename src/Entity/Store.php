<?php

namespace Base\Market\Entity;

use Base\Market\Entity\Product\Taxon as ProductTaxon;
use Base\Market\Entity\Review\Taxon as ReviewTaxon;
use Base\Market\Entity\Sales\Region;
use Base\Market\Model\MerchantInterface;
use Base\Market\Repository\StoreRepository;
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
        return $this->getRouter()->generate('market_store', array_merge($routeParameters, ['slug' => $this->getSlug()]), $referenceType);
    }

    public function __construct(?MerchantInterface $merchant = null)
    {
        parent::__construct($merchant);
        $this->regions = new ArrayCollection();
        $this->orders = new ArrayCollection();
        $this->reviews = new ArrayCollection();
        $this->products = new ArrayCollection();

        $this->currency = $this->getParameterBag('market.default_currency');

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

    public function addRegion(Region $region): self
    {
        return $this->addChild($region);
    }

    public function removeRegion(Region $region): self
    {
        return $this->removeChild($region);
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
    protected $open;

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
