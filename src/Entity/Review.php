<?php

namespace Base\Market\Entity;

use Base\Entity\User;
use Base\Market\Repository\ReviewRepository;
use Base\Database\Attribute\Hierarchify;
use Base\Database\Attribute\Uploader;
use Base\Database\Attribute\Cache;
use Base\Database\Attribute\DiscriminatorEntry;
use Base\Entity\Thread;
use Base\Service\Model\AutocompleteInterface;
use Base\Service\Model\IconizeInterface;
use Base\Service\Model\LinkableInterface;
use Base\Validator\Constraints as AssertBase;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

#[ORM\Entity(repositoryClass: ReviewRepository::class)]
#[\Base\Database\Attribute\Cache(usage: 'NONSTRICT_READ_WRITE', associations: 'ALL')]
#[\Base\Database\Attribute\DiscriminatorEntry]
#[\Base\Database\Attribute\Hierarchify(hierarchy: ['store', 'reviews'], separator: '/')]
class Review extends Thread implements IconizeInterface, AutocompleteInterface, LinkableInterface
{

    public function __toKey(mixed ...$variadic): string
    {
        $variadic[] = $this->getId();
        $variadic[] = $this->getUpdatedAt();
        return $this->__toDefaultKey(...$variadic);
    }

    public static function __iconizeStatic(): ?array
    {
        return ['fa-solid fa-search'];
    }

    public function __typesense(): ?string
    {
        return $this->__autocomplete();
    }

    public function __autocompleteData(): array
    {
        return [];
    }

    public function __autocomplete(): string
    {
        $identifierName = camel2snake(strtolower(str_replace(['App\\', '\\'], ['', '/'], static::class)));

        return $this->getTwig()->render($identifierName . '.html.twig', ['review' => $this]);
    }

    public function __toLink(array $routeParameters = [], int $referenceType = UrlGeneratorInterface::ABSOLUTE_PATH): ?string
    {
        if (null == $this->getStore()) {
            return null;
        }

        if (null == $this->getProduct()) {
            return null;
        }

        // The product says what it is routed as, rather than this class
        // switching on concrete types it should know nothing about. The old
        // form named Wallpaper and Wallpaper\Sample directly, which is
        // exactly the coupling that stopped this being a bundle: a review
        // cannot depend on one shop's product catalogue.
        $identifierClass = $this->getProduct()->getRouteIdentifierClass();

        $identifierName = camel2snake(class_basename($identifierClass));

        $routeName = 'app_' . $identifierName . 'Review';
        $routeParameters = array_merge($routeParameters, [
            'store' => $this->getStore()->getSlug(),
            $identifierName => $this->getProduct()->getSlug(),
            'id' => $this->getId(),
        ]);

        return $this->getRouter()->generate($routeName, $routeParameters, $referenceType);
    }

    /**
     * @param User|null $reviewer
     * @param array $pictures
     */
    public function __construct(?User $reviewer = null, $rating = 1.0, array $pictures = [])
    {
        parent::__construct($reviewer);
        $this->rating = $rating;
        $this->pictures = $pictures;
        $this->taxa = new ArrayCollection();
    }

    #[ORM\Column(type: 'float')]
    protected $rating;

    public function getRating(): ?float
    {
        return $this->rating;
    }

    public function setRating(float $rating): self
    {
        $this->rating = min(1.0, max(0.0, $rating));

        return $this;
    }

    #[ORM\Column(type: 'array', nullable: true)]
    #[\Base\Database\Attribute\Uploader(max_size: '20MB', mime_types: ['image/*'], fetch: true)]
    #[AssertBase\File(max_size: '20MB', mime_types: ['image/*'], groups: ['new', 'edit'])]
    protected $pictures;

    /**
     * @param int $i
     * @return mixed|null
     */
    public function getPicture(int $i = 0)
    {
        return $this->getPictures()[$i] ?? null;
    }

    /**
     * @return array|mixed|File|null
     */
    public function getPictures()
    {
        return \Base\Database\Attribute\Uploader::getPublic($this, 'pictures');
    }

    /**
     * @return array|mixed|File|null
     */
    public function getPictureFiles()
    {
        return \Base\Database\Attribute\Uploader::get($this, 'pictures');
    }

    /**
     * @param array $pictures
     * @return $this
     */
    /**
     * @param array $pictures
     * @return $this
     */
    public function setPictures(array $pictures)
    {
        $this->pictures = $pictures;

        return $this;
    }

    #[ORM\JoinColumn(name: 'product_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    #[ORM\ManyToOne(targetEntity: Product::class, inversedBy: 'reviews')]
    protected $product;

    public function getProduct(): ?Product
    {
        return $this->product;
    }

    public function setProduct(?Product $product): self
    {
        $this->product = $product;

        return $this;
    }

    public function getColors(): array
    {
        return $this->getProduct()->getColors() ?? [];
    }

    /**
     * @param int $i
     * @return mixed|null
     */
    public function getAuthor(int $i = 0)
    {
        return $this->getProduct()?->getAuthor($i);
    }

    public function getAuthors(): Collection
    {
        return $this->getProduct()?->getAuthors();
    }

    #[ORM\ManyToOne(targetEntity: Store::class, inversedBy: 'reviews')]
    protected $store;

    public function getStore(): ?Store
    {
        return $this->store;
    }

    public function setStore(?Store $store): self
    {
        $this->store = $store;

        return $this;
    }

    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    #[ORM\ManyToOne(targetEntity: Order::class, inversedBy: 'reviews')]
    protected $order;

    public function getOrder(): ?Order
    {
        return $this->order;
    }

    public function setOrder(?Order $order): self
    {
        $this->order = $order;

        return $this;
    }
}
