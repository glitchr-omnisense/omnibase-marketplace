<?php

namespace Base\Marketplace\Controller\Client;

use Base\Marketplace\Entity\Product;
use Base\Marketplace\Entity\Store;
use Base\Marketplace\Service\Cart;
use Base\Service\PaginatorInterface;
use Base\Contract\UnitOfWork;
use Base\Database\UnitOfWorks;
use Base\Database\Repository\Finder\Key;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Browsing: the stores, a store's shelf, a product. Route names are
 * marketplace_*; the paths are French, as the shop's first site was - an application
 * wanting others overrides the routes, not the controller.
 */
class StoreController extends AbstractController
{
    public function __construct(
        private readonly UnitOfWork $unitOfWork,
        private readonly PaginatorInterface $paginator,
        private readonly Cart $cart,
        #[Autowire('%marketplace.products_per_page%')] private readonly int $perPage = 24,
    ) {
    }

    #[Route('/boutiques', name: 'marketplace_stores')]
    public function Stores(): Response
    {
        return $this->render('@Marketplace/client/stores.html.twig', [
            'stores' => UnitOfWorks::repositories($this->unitOfWork)->of(Store::class)->findBy(['open' => true]),
        ]);
    }

    #[Route('/boutique/{slug}', name: 'marketplace_store', requirements: ['slug' => '[a-z0-9\-]+'])]
    public function Store(Request $request, string $slug): Response
    {
        $store = $this->findStore($slug);

        $products = UnitOfWorks::repositories($this->unitOfWork)->of(Product::class);

        return $this->render('@Marketplace/client/store.html.twig', [
            'store' => $store,
            'products' => $this->paginator->paginate($products, ['parent' => Key::of($store)], ['createdAt' => 'DESC'], page: max(1, $request->query->getInt('page', 1)), pageSize: $this->perPage),
            'cart' => $this->cart->of($store),
        ]);
    }

    #[Route('/boutique/{store}/{slug}', name: 'marketplace_product', requirements: ['store' => '[a-z0-9\-]+', 'slug' => '[a-z0-9\-]+'])]
    public function Product(string $store, string $slug): Response
    {
        $shop = $this->findStore($store);
        $product = UnitOfWorks::repositories($this->unitOfWork)->of(Product::class)->findOneBy(['slug' => $slug]);
        if (!$product instanceof Product || $product->getStore() !== $shop) {
            throw $this->createNotFoundException('Unknown product.');
        }

        return $this->render('@Marketplace/client/product.html.twig', [
            'store' => $shop,
            'product' => $product,
            'cart' => $this->cart->of($shop),
        ]);
    }

    private function findStore(string $slug): Store
    {
        $store = UnitOfWorks::repositories($this->unitOfWork)->of(Store::class)->findOneBy(['slug' => $slug]);
        if (!$store instanceof Store) {
            throw $this->createNotFoundException('Unknown store.');
        }

        return $store;
    }
}
