<?php

namespace Tests\Base\Marketplace\Wishlist;

use Omnitrade\Action\ActionInterface;
use Omnitrade\Config;
use Omnitrade\GatewayFactory;
use Omnitrade\Model\Account;
use Omnitrade\Model\Money;
use Omnitrade\Model\Offer;
use Omnitrade\Model\Product;
use Omnitrade\Model\ProductVariant;
use Omnitrade\Model\Status;
use Omnitrade\Model\Transaction;
use Omnitrade\Request\AccountLink;
use Omnitrade\Request\AffiliateLink;
use Omnitrade\Request\CreateAccount;
use Omnitrade\Request\FetchAccount;
use Omnitrade\Request\FetchProduct;
use Omnitrade\Request\FetchTransaction;
use Omnitrade\Request\Purchase;
use Omnitrade\Request\Request;

/** A provider for the tests: connected accounts, hosted payments, a product page, an affiliate link - all in memory. */
final class StubProvider extends GatewayFactory implements ActionInterface
{
    /** @var list<Request> every request it answered */
    public array $requests = [];
    public Status $paymentStatus = Status::PENDING;
    public bool $accountReady = false;

    protected function populateConfig(Config $config): void
    {
        $config->defaults(['omnitrade.factory_name' => 'stub', 'omnitrade.factory_title' => 'Stub', 'omnitrade.required_options' => [], 'omnitrade.action.all' => $this]);
    }

    public function supports(Request $request): bool
    {
        return $request instanceof Purchase || $request instanceof FetchTransaction || $request instanceof CreateAccount || $request instanceof AccountLink
            || $request instanceof FetchAccount || $request instanceof FetchProduct || $request instanceof AffiliateLink;
    }

    public function execute(Request $request): void
    {
        $this->requests[] = $request;
        $request->setResult(match (true) {
            $request instanceof Purchase => new Transaction('stub', 'cs_'.$request->payment->reference, Status::PENDING, $request->payment->amount, 'https://pay.test/'.$request->payment->reference),
            $request instanceof FetchTransaction => new Transaction('stub', $request->reference, $this->paymentStatus),
            $request instanceof CreateAccount => new Account('stub', 'acct_'.bin2hex(random_bytes(4)), country: strtoupper($request->country), email: $request->email, requirements: ['external_account']),
            $request instanceof FetchAccount => new Account('stub', $request->reference, detailsSubmitted: $this->accountReady, chargesEnabled: $this->accountReady, payoutsEnabled: $this->accountReady),
            $request instanceof AccountLink => 'https://connect.test/'.$request->reference,
            $request instanceof FetchProduct => str_contains((string) $request->reference->url, 'poussette') ? new Product('stub', (string) $request->reference->url, 'Poussette Yoyo', 'Légère.', url: $request->reference->url, variants: [new ProductVariant('v1', offers: [new Offer(Money::of(44990, 'EUR'))])], media: [new \Omnitrade\Model\Media('https://cdn.test/yoyo.jpg')], merchant: new \Omnitrade\Model\Merchant('La Boutique')) : null,
            $request instanceof AffiliateLink => str_contains((string) $request->reference->url, 'boutique') ? $request->reference->url.'?aff=1' : null,
        });
    }
}
