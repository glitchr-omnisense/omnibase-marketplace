<?php

namespace Base\Marketplace\Wishlist;

use Base\Entity\User;
use Base\Marketplace\Entity\Product;
use Base\Marketplace\Entity\Wishlist\Item;
use Base\Marketplace\Entity\Wishlist\Wishlist;
use Base\Marketplace\Enum\WishlistItemKind;
use Base\Marketplace\Enum\WishlistKind;
use Base\Marketplace\Model\WishlistHolderInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Lists and their wishes: made, filled from a pasted address (read through
 * glitchr/omnitrade when it is installed), from this shop's catalogue or by
 * hand, and their prices read again when they are old.
 */
class Wishlists
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ?ProductLookup $lookup = null,
        #[Autowire('%marketplace.wishlist.price_ttl%')] private readonly int $priceTtl = 86400,
    ) {
    }

    public function create(User $owner, string $title, WishlistKind $kind = WishlistKind::GIFTS, ?WishlistHolderInterface $holder = null): Wishlist
    {
        $wishlist = new Wishlist($owner, $title, $kind, $holder);
        $this->entityManager->persist($wishlist);
        $this->entityManager->flush();

        return $wishlist;
    }

    /**
     * A wish from the address of its page, in any shop: what a gateway reads
     * there fills it (title, picture, price, merchant); nothing read, it is
     * kept with its address alone, for its owner to complete.
     *
     * @throws WishlistException when it is not an address
     */
    public function addFromUrl(Wishlist $wishlist, string $url, WishlistItemKind $kind = WishlistItemKind::OBJECT): Item
    {
        $url = trim($url);
        if (!preg_match('~^https?://[^\s/]+\.[^\s/]+~i', $url) || false === filter_var($url, \FILTER_VALIDATE_URL)) {
            throw new WishlistException('wishlist.error.url');
        }
        $item = new Item((string) preg_replace('~^www\.~', '', (string) parse_url($url, \PHP_URL_HOST)), $kind, null, $wishlist->getCurrency());
        $item->setUrl($url);
        $item->setPosition($wishlist->getItems()->count());
        $this->read($item);
        $wishlist->addItem($item);
        $this->entityManager->persist($item);
        $this->entityManager->flush();

        return $item;
    }

    /** A wish that is one of this shop's products: its price is the shop's, it is bought here. */
    public function addProduct(Wishlist $wishlist, Product $product, int $quantity = 1): Item
    {
        $item = new Item((string) $product, WishlistItemKind::OBJECT, (int) $product->getUnitPrice(), (string) ($product->getCurrency() ?: $wishlist->getCurrency()));
        $item->setProduct($product)->setQuantity($quantity)->setPosition($wishlist->getItems()->count());
        $wishlist->addItem($item);
        $this->entityManager->persist($item);
        $this->entityManager->flush();

        return $item;
    }

    /** A pot (FUND; $goal shown, no ceiling) or an object shared between givers (SHARE; $goal its price). */
    public function addFund(Wishlist $wishlist, string $title, ?int $goal = null, WishlistItemKind $kind = WishlistItemKind::FUND, ?string $description = null): Item
    {
        if (!$kind->takesMoney()) {
            throw new \InvalidArgumentException('A fund is a FUND or a SHARE.');
        }
        if (WishlistItemKind::SHARE === $kind && (null === $goal || $goal <= 0)) {
            throw new WishlistException('wishlist.error.share_price');
        }
        $item = new Item($title, $kind, $goal, $wishlist->getCurrency());
        $item->setDescription($description)->setPosition($wishlist->getItems()->count());
        $wishlist->addItem($item);
        $this->entityManager->persist($item);
        $this->entityManager->flush();

        return $item;
    }

    /**
     * The prices read longer ago than marketplace.wishlist.price_ttl, read
     * again (shown with their date: a price is the page's at the time).
     *
     * @return int how many were read
     */
    public function refreshPrices(?\DateTimeImmutable $now = null, int $limit = 200): int
    {
        if (null === $this->lookup) {
            return 0;
        }
        $before = ($now ?? new \DateTimeImmutable())->modify(sprintf('-%d seconds', $this->priceTtl));
        $items = $this->entityManager->createQuery('SELECT i FROM '.Item::class.' i JOIN i.wishlist w WHERE w.open = true AND i.url IS NOT NULL AND i.product IS NULL AND (i.priceFetchedAt IS NULL OR i.priceFetchedAt < :before) ORDER BY i.priceFetchedAt ASC')
            ->setParameter('before', $before)->setMaxResults($limit)->getResult();
        $read = 0;
        foreach ($items as $item) {
            $read += (int) $this->read($item, true);
        }
        $this->entityManager->flush();

        return $read;
    }

    /** Fills a wish from its page; on a refresh only its price, availability's date and link move. */
    public function read(Item $item, bool $priceOnly = false): bool
    {
        if (null === $this->lookup || null === $item->getUrl()) {
            return false;
        }
        $found = $this->lookup->lookup($item->getUrl());
        $item->setAffiliateUrl($this->lookup->affiliateLink($item->getUrl()) ?? $item->getAffiliateUrl());
        if (null === $found) {
            return false;
        }
        $product = $found['product'];
        $price = $product->price();
        $item->setSource($found['gateway'], $product->reference);
        if (null !== $price) {
            $item->setPrice($price->amount, new \DateTimeImmutable());
            $item->setCurrency($price->currency);
        }
        if (!$priceOnly) {
            $item->setTitle($product->title);
            $item->setDescription($product->description ? mb_substr($product->description, 0, 600) : null);
            $item->setImageUrl($product->media[0]->url ?? null);
            $item->setMerchant($product->merchant?->name);
        }

        return true;
    }
}
