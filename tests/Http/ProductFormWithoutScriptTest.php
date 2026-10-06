<?php

namespace Tests\Base\Marketplace\Http;

use Base\Marketplace\Entity\Product;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Tests\Base\Marketplace\MarketplaceKernelTestCase;
use Tests\Base\Marketplace\ShopFixtureTrait;

/**
 * A product typed in the back office as its form is sent when no script
 * ran - a browser that blocks them, a page whose script failed: the fields
 * as the HTML prints them, nothing a script would have filled.
 */
final class ProductFormWithoutScriptTest extends MarketplaceKernelTestCase
{
    use ShopFixtureTrait;

    /** @var array<string, string> */
    private array $cookies = [];

    protected function setUp(): void
    {
        parent::setUp();
        if (!class_exists('Base\\Admin\\Controller\\AbstractCrudController')) {
            self::markTestSkipped('Needs omnibase/admin.');
        }
        $_SERVER['REMOTE_ADDR'] ??= '127.0.0.1';
        $_SERVER['HTTP_USER_AGENT'] ??= 'phpunit';

        // Signed in as a creator of the marketplace, by a session as after a real sign-in.
        $admin = $this->user('creator');
        $admin->setRoles(['ROLE_SUPERADMIN']);
        $this->entityManager->flush();
        $session = self::getContainer()->get('session.factory')->createSession();
        $session->set('_security_main', serialize(new UsernamePasswordToken($admin, 'main', $admin->getRoles())));
        $session->save();
        $this->cookies = [$session->getName() => $session->getId()];
    }

    /** @param array<string, mixed>|null $post */
    private function ask(string $path, ?array $post = null): Response
    {
        $request = Request::create($path, null === $post ? 'GET' : 'POST', $post ?? [], $this->cookies, [], ['HTTP_ORIGIN' => 'http://localhost']);
        $response = self::$kernel->handle($request);
        foreach ($response->headers->getCookies() as $cookie) {
            $this->cookies[$cookie->getName()] = (string) $cookie->getValue();
        }

        return $response;
    }

    /** A page opened as a browser does, to where it leads (a product's address is its slug's). */
    private function open(string $path): Response
    {
        $response = $this->ask($path);
        for ($n = 0; $n < 3 && $response->isRedirection(); ++$n) {
            $response = $this->ask((string) parse_url((string) $response->headers->get('Location'), \PHP_URL_PATH));
        }

        return $response;
    }

    /**
     * What the form given back says is wrong, by field ('' for the form itself).
     *
     * @return array<string, list<string>>
     */
    private function errors(Response $response): array
    {
        $errors = [];
        foreach (explode('<div class="form-row"', (string) $response->getContent()) as $n => $row) {
            if (!preg_match_all('~<li><span>(.*?)</span></li>~s', $row, $messages)) {
                continue;
            }
            $field = 0 === $n ? '' : (preg_match('~name="crud_form\[([^\]"]+)\]~', $row, $name) ? $name[1] : '?');
            $errors[$field] = array_map(static fn (string $message) => html_entity_decode(trim(strip_tags($message))), $messages[1]);
        }

        return $errors;
    }

    private function said(Response $response): string
    {
        $status = $response->getStatusCode();
        if ($status >= 500 || 404 === $status) {
            return $status.' '.substr(trim(preg_replace('/\s+/', ' ', strip_tags((string) preg_replace('~<(style|script)\b.*?</\1>~s', '', (string) $response->getContent())))), 0, 600);
        }

        return $status.' '.$response->headers->get('Location').' '.json_encode($this->errors($response), \JSON_UNESCAPED_UNICODE);
    }

    /**
     * A product's form as printed: its fields' names and the values a browser would send back untouched.
     *
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function form(string $path = '/admin/products/new'): array
    {
        $response = $this->ask($path);
        self::assertSame(200, $response->getStatusCode(), $this->said($response));
        $html = (string) $response->getContent();

        // As a browser gathers them: an input's value, a ticked box, a textarea's text, a <select>'s
        // selected options - the first one of a single <select> when none is marked.
        $pairs = [];
        preg_match_all('~<input[^>]*\sname="(crud_form\[[^"]+)"[^>]*>~', $html, $inputs, \PREG_SET_ORDER);
        foreach ($inputs as [$tag, $name]) {
            if (preg_match('/type="(file|submit|button)"/', $tag) || (preg_match('/type="(checkbox|radio)"/', $tag) && !preg_match('/\schecked/', $tag))) {
                continue;
            }
            $pairs[] = [$name, preg_match('/\svalue="([^"]*)"/', $tag, $value) ? $value[1] : ''];
        }
        preg_match_all('~<textarea[^>]*\sname="(crud_form\[[^"]+)"[^>]*>(.*?)</textarea>~s', $html, $areas, \PREG_SET_ORDER);
        foreach ($areas as [, $name, $text]) {
            $pairs[] = [$name, $text];
        }
        preg_match_all('~<select([^>]*)\sname="(crud_form\[[^"]+)"([^>]*)>(.*?)</select>~s', $html, $selects, \PREG_SET_ORDER);
        foreach ($selects as [, $before, $name, $after, $options]) {
            preg_match_all('~<option([^>]*)>~', $options, $tags);
            $values = array_map(static fn (string $tag) => [preg_match('/\svalue="([^"]*)"/', $tag, $value) ? $value[1] : '', (bool) preg_match('/\sselected/', $tag)], $tags[1]);
            $chosen = array_column(array_filter($values, static fn (array $option) => $option[1]), 0);
            if (!$chosen && $values && !preg_match('/\smultiple/', $before.$after)) {
                $chosen = [$values[0][0]];
            }
            foreach ($chosen as $value) {
                $pairs[] = [$name, $value];
            }
        }
        parse_str(implode('&', array_map(static fn (array $pair) => urlencode($pair[0]).'='.urlencode(html_entity_decode($pair[1])), $pairs)), $sent);

        return [$html, $sent['crud_form'] ?? []];
    }

    /** The <select> of a field, as printed. */
    private function select(string $html, string $field): string
    {
        return preg_match('~<select[^>]*name="crud_form\['.preg_quote($field, '~').'\](?:\[choice\])?(?:\[\])?"[^>]*>.*?</select>~s', $html, $select) ? $select[0] : '';
    }

    /**
     * What the browser sends back for a product's form, the fields given here typed by hand.
     *
     * @param array<string, mixed> $typed
     */
    private function send(array $typed, string $path = '/admin/products/new'): Response
    {
        [, $fields] = $this->form($path);

        return $this->ask($path, ['crud_form' => array_replace($fields, $typed)]);
    }

    private function kept(string $slug): ?Product
    {
        $this->entityManager->clear();

        return $this->entityManager->getRepository(Product::class)->findOneBy(['slug' => $slug]);
    }

    public function testTheStoreIsChosenFromAListThatIsInThePage(): void
    {
        $store = $this->store();
        [$html] = $this->form();

        $select = $this->select($html, 'parent');
        self::assertNotSame('', $select, 'the store is a <select> of the form: '.(preg_match('~.{0,300}crud_form_parent.{0,1500}~s', $html, $near) ? $near[0] : 'no field at all'));
        self::assertMatchesRegularExpression('~<option[^>]*value="'.$store->getId().'"[^>]*>\s*'.preg_quote($store->getTitle(), '~').'~', $select, 'the stores are options of it, before any script: '.substr($select, 0, 1500));
    }

    public function testAProductIsCreatedInTheStoreChosenWithoutAScript(): void
    {
        $store = $this->store();
        $slug = 'sans-script-'.bin2hex(random_bytes(3));

        // The option chosen, sent as the <select> names it.
        $response = $this->send(['title' => 'Mug sans script', 'slug' => $slug, 'parent' => ['choice' => (string) $store->getId()], 'unitPrice' => '1900']);
        self::assertSame(302, $response->getStatusCode(), $this->said($response));

        $product = $this->kept($slug);
        self::assertNotNull($product, 'the product is kept');
        self::assertSame($store->getId(), $product->getStore()?->getId(), 'in the store chosen');
        self::assertSame(1900, $product->getUnitPrice());

        // Its pages open: the form again, with its store selected, and the shop's.
        $edit = $this->open('/admin/products/'.$product->getId().'/edit');
        self::assertSame(200, $edit->getStatusCode(), $this->said($edit));
        self::assertMatchesRegularExpression('~<option[^>]*value="'.$store->getId().'"[^>]*selected~', $this->select((string) $edit->getContent(), 'parent'));
        $page = $this->open('/boutique/'.$store->getSlug().'/'.$slug);
        self::assertSame(200, $page->getStatusCode(), $this->said($page));
    }

    public function testAProductTypedWithoutAPriceIsRefusedOnItsField(): void
    {
        $store = $this->store();
        $slug = 'sans-prix-'.bin2hex(random_bytes(3));

        $response = $this->send(['title' => 'Sur devis', 'slug' => $slug, 'parent' => ['choice' => (string) $store->getId()], 'unitPrice' => '']);

        // The form given back with what is wrong, not an error page - and nothing kept.
        self::assertSame(422, $response->getStatusCode(), $this->said($response));
        self::assertSame(['unitPrice'], array_keys($this->errors($response)), 'the price field says it, and it alone: '.$this->said($response));
        self::assertNull($this->kept($slug));
    }

    public function testAPriceOfZeroIsAPrice(): void
    {
        $store = $this->store();
        $slug = 'sur-devis-'.bin2hex(random_bytes(3));

        $response = $this->send(['title' => 'Sur devis', 'slug' => $slug, 'parent' => ['choice' => (string) $store->getId()], 'unitPrice' => '0']);
        self::assertSame(302, $response->getStatusCode(), $this->said($response));

        $product = $this->kept($slug);
        self::assertNotNull($product);
        self::assertSame(0, $product->getUnitPrice());
        foreach (['/admin/products/'.$product->getId().'/edit', '/admin/products', '/boutique/'.$store->getSlug().'/'.$slug, '/boutique/'.$store->getSlug()] as $path) {
            $page = $this->open($path);
            self::assertSame(200, $page->getStatusCode(), $path.': '.$this->said($page));
        }
    }

    public function testEmptyingAProductsPriceIsRefusedAndItsPriceKept(): void
    {
        $product = $this->goods('mug', 1900);
        [$id, $slug] = [$product->getId(), $product->getSlug()];
        $path = '/admin/products/'.$slug.'/edit';
        $read = function () use ($id): Product {
            $this->entityManager->clear();

            return $this->entityManager->find(Product::class, $id);
        };

        // Its form sent back untouched changes nothing...
        $response = $this->send([], $path);
        self::assertSame(302, $response->getStatusCode(), $this->said($response));
        self::assertSame([$slug, 1900, $this->store()->getId()], [$read()->getSlug(), $read()->getUnitPrice(), $read()->getStore()?->getId()]);

        // ...and with its price emptied it is given back, the price as it was.
        $response = $this->send(['unitPrice' => ''], $path);
        self::assertSame(422, $response->getStatusCode(), $this->said($response));
        self::assertSame(['unitPrice'], array_keys($this->errors($response)), $this->said($response));
        self::assertSame(1900, $read()->getUnitPrice());
    }

    public function testAProductSentWithNoStoreChosenIsNotAnErrorPage(): void
    {
        $this->store();
        $slug = 'sans-boutique-'.bin2hex(random_bytes(3));

        // The <select> left on its first line, "choose".
        $response = $this->send(['title' => 'Sans boutique', 'slug' => $slug, 'unitPrice' => '1900']);
        self::assertSame(422, $response->getStatusCode(), $this->said($response));
        self::assertSame(['parent'], array_keys($this->errors($response)), $this->said($response));
        self::assertNull($this->kept($slug));
    }
}
