<?php

namespace Base\Marketplace\Entity;

use Base\Database\Attribute\Uploader;
use Base\Entity\Thread;
use Base\Marketplace\Repository\BrandRepository;
use Base\Service\Model\LinkableInterface;
use Base\Validator\Constraints as AssertBase;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Who makes a product: a wine estate or a négociant house, a brewery, a
 * maker. A thread - its title the name, its content the story (translated
 * like any thread) - with a logo, where it is (country, region), a gallery,
 * its own site, and its products (Product::$brand).
 *
 * It is what Shopify calls a product's vendor and WooCommerce its brand:
 * the catalogue synchronisation (Catalogue\PlatformSynchronizer) files a
 * platform's product under the brand of that name.
 */
#[ORM\Entity(repositoryClass: BrandRepository::class)]
#[\Base\Database\Attribute\Cache(usage: 'NONSTRICT_READ_WRITE', associations: 'ALL')]
#[\Base\Database\Attribute\DiscriminatorEntry]
class Brand extends Thread implements \Base\Database\Entity\Extension\TranslatableInterface, LinkableInterface
{
    use \Base\Database\Entity\Extension\TranslatableTrait;

    public static function __iconizeStatic(): ?array
    {
        return ['fa-solid fa-award'];
    }

    /** The application names the page of a brand: marketplace.brand_route (none: no link). */
    public function __toLink(array $routeParameters = [], int $referenceType = UrlGeneratorInterface::ABSOLUTE_PATH): ?string
    {
        $route = $this->getParameterBag('marketplace.brand_route');
        if (!$route) {
            return null;
        }

        return $this->getRouter()->generate($route, array_merge($routeParameters, ['slug' => $this->getSlug()]), $referenceType);
    }

    public function __construct(?string $name = null)
    {
        parent::__construct(null, null, $name);
        $this->products = new ArrayCollection();
    }

    #[ORM\OneToMany(targetEntity: Product::class, mappedBy: 'brand')]
    protected $products;

    /** @return Collection<int, Product> */
    public function getProducts(): Collection
    {
        return $this->products;
    }

    #[ORM\Column(type: 'text', nullable: true)]
    #[AssertBase\File(max_size: '5MB', groups: ['new', 'edit'])]
    #[Uploader(max_size: '5MB', mime_types: ['image/*'])]
    protected $logo;

    public function getLogo()
    {
        return Uploader::getPublic($this, 'logo');
    }

    public function getLogoFile()
    {
        return Uploader::get($this, 'logo');
    }

    public function setLogo($logo): self
    {
        $this->logo = $logo;

        return $this;
    }

    #[ORM\Column(type: 'array', nullable: true)]
    #[Uploader(mime_types: ['image/*'])]
    #[AssertBase\File(mime_types: ['image/*'], groups: ['new', 'edit'], max_size: '10MB')]
    protected $gallery;

    /** The pictures of the place: the vineyard, the cellar, the people. */
    public function getGallery(): array
    {
        return Uploader::getPublic($this, 'gallery') ?? [];
    }

    public function setGallery($gallery): self
    {
        $this->gallery = $gallery;

        return $this;
    }

    /** ISO 3166-1 alpha-2. */
    #[ORM\Column(type: 'string', length: 2, nullable: true)]
    #[Assert\Country]
    protected $country;

    public function getCountry(): ?string
    {
        return $this->country;
    }

    public function setCountry(?string $country): self
    {
        $this->country = $country ? strtoupper($country) : null;

        return $this;
    }

    /** The region, as the trade names it: Bordeaux, Bourgogne, Niigata. */
    #[ORM\Column(type: 'string', length: 128, nullable: true)]
    protected $region;

    public function getRegion(): ?string
    {
        return $this->region;
    }

    public function setRegion(?string $region): self
    {
        $this->region = $region;

        return $this;
    }

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    #[Assert\Url]
    protected $website;

    public function getWebsite(): ?string
    {
        return $this->website;
    }

    public function setWebsite(?string $website): self
    {
        $this->website = $website;

        return $this;
    }
}
