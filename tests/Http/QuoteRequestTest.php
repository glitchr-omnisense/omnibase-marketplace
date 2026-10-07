<?php

namespace Tests\Base\Marketplace\Http;

use Base\Marketplace\Entity\Attachment;
use Base\Marketplace\Entity\Quote;
use Base\Marketplace\Form\QuoteRequestType;
use Base\Marketplace\Model\QuoteRequest as QuoteRequestModel;
use Base\Marketplace\Service\Attachments;
use Base\Service\FormGuard;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Tests\Base\Marketplace\MarketplaceKernelTestCase;

/**
 * The quote form as a visitor sends it: a phone number, files kept out of
 * the public directory and read from the back office only, omnibase's
 * data-protection notice, and the core's forms' guard (option `guard`): a
 * filled trap, a form sent faster than its delay, a missing captcha token
 * refused, nothing kept. In the core's harness (no glitchr/omniguard), the
 * trap and the time alone; in a site with omniguard's "fixed" test gateway,
 * the captcha too.
 */
final class QuoteRequestTest extends MarketplaceKernelTestCase
{
    private function path(): string
    {
        return '/'.self::getContainer()->getParameter('marketplace.quotes.path');
    }

    /** The form's page: its fields' names and values, and the visitor's session. */
    private function form(): array
    {
        $request = Request::create($this->path());
        $response = self::$kernel->handle($request);
        self::assertSame(200, $response->getStatusCode(), substr((string) $response->getContent(), 0, 2000));
        $html = (string) $response->getContent();
        // Symfony's stateless CSRF: a fixed marker in the form, the request's Origin checked against its host.
        preg_match('/<input[^>]*name="quote_request\[(_csrf_token|_token)\]"[^>]*value="([^"]+)"/', $html, $token);
        $cookies = [];
        foreach ($response->headers->getCookies() as $cookie) {
            $cookies[$cookie->getName()] = $cookie->getValue();
        }
        if ($request->hasSession()) {
            $cookies[$request->getSession()->getName()] = $request->getSession()->getId();
        }

                return [$html, $token ? [$token[1] => $token[2]] : [], $cookies];
    }

    private function send(array $fields, array $files = [], ?string $captcha = 'omniguard-fixed-token'): Response
    {
        [$html, $token, $cookies] = $this->form();
        $fields += ['contactName' => 'Félix Morvan', 'email' => 'felix@example.org', 'title' => 'Enseigne drapeau', 'request' => 'Une enseigne drapeau lumineuse pour la boutique, pose comprise.'];
        $fields += $token;
        // The guard's stamp, signed by the application, as if the page had been open ten seconds.
        $fields += ['guard_website' => '', 'guard_opened' => self::getContainer()->get(FormGuard::class)->stamp(time() - 10)];
        $post = ['quote_request' => $fields];
        if (null !== $captcha && str_contains($html, 'omniguard-token')) {
            // glitchr/omniguard's "fixed" test gateway: its token, outside the form.
            $post['omniguard-token'] = $captcha;
        }
        if (str_contains($html, 'quote_request[_captcha]')) {
            // The host protects its forms with reCAPTCHA (the harness does, on Google's public test keys, which accept any answer).
            $fields['_captcha'] = 'test';
        }

        $response = self::$kernel->handle(Request::create($this->path(), 'POST', $post, $cookies, $files ? ['quote_request' => ['files' => $files]] : [], ['HTTP_ORIGIN' => 'http://localhost']));
        if (422 === $response->getStatusCode() && preg_match('/captcha/i', strip_tags((string) $response->getContent()))) {
            self::markTestSkipped('The host\'s reCAPTCHA could not be verified from here (no network).');
        }

        return $response;
    }

    private function upload(string $name, string $content): UploadedFile
    {
        $path = sys_get_temp_dir().'/quote-'.bin2hex(random_bytes(4));
        file_put_contents($path, $content);

        return new UploadedFile($path, $name, null, null, true);
    }

    public function testTheFormAsksAPhoneAndFilesAndShowsTheNotice(): void
    {
        [$html] = $this->form();

        self::assertStringContainsString('name="quote_request[phone]"', $html);
        self::assertStringContainsString('name="quote_request[files][]"', $html);
        self::assertStringContainsString('name="quote_request[guard_website]"', $html, 'the guard\'s trap');
        self::assertStringContainsString('name="quote_request[guard_opened]"', $html, 'the guard\'s stamp');
        self::assertStringNotContainsString('name="quote_request[website]"', $html, 'no trap of the form\'s own');
        self::assertStringContainsString('multipart/form-data', $html);
    }

    public function testARequestWithItsFilesReachesTheBackOfficeOnly(): void
    {
        $response = $this->send(['phone' => '03 55 40 13 81', 'companyName' => 'Soif de Pub'], [$this->upload('logo.pdf', '%PDF-1.4 logo'), $this->upload('façade.png', 'png')]);
        self::assertSame(200, $response->getStatusCode(), substr(strip_tags((string) $response->getContent()), 0, 3000));

        $quote = $this->entityManager->getRepository(Quote::class)->findOneBy(['email' => 'felix@example.org'], ['id' => 'DESC']);
        self::assertInstanceOf(Quote::class, $quote);
        self::assertSame('03 55 40 13 81', $quote->getPhone());
        $this->entityManager->refresh($quote);
        self::assertCount(2, $quote->getAttachments());

        /** @var Attachment $attachment */
        $attachment = $quote->getAttachments()->first();
        $file = self::getContainer()->get(Attachments::class)->file($attachment);
        self::assertFileExists($file);
        self::assertStringNotContainsString('/public/', $file, 'not under the public directory');

        // Nobody signed in: the file is the shop's.
        $anonymous = self::$kernel->handle(Request::create('/marketplace/piece-jointe/'.$attachment->getId()));
        self::assertContains($anonymous->getStatusCode(), [302, 401, 403]);

        // With the signed link the shop hands out: a download.
        $link = self::getContainer()->get('uri_signer')->sign('http://localhost/marketplace/piece-jointe/'.$attachment->getId(), new \DateTimeImmutable('+60 seconds'));
        $signed = self::$kernel->handle(Request::create($link));
        self::assertSame(200, $signed->getStatusCode());
        self::assertStringContainsString('attachment;', (string) $signed->headers->get('Content-Disposition'));
    }

    public function testARobotInTheTrapIsRefusedAndNothingIsKept(): void
    {
        $before = $this->entityManager->getRepository(Quote::class)->count([]);
        $response = $this->send(['guard_website' => 'https://spam.example', 'email' => 'robot@example.org']);

        self::assertSame(422, $response->getStatusCode());
        self::assertSame($before, $this->entityManager->getRepository(Quote::class)->count([]));
    }

    public function testARequestSentFasterThanItsDelayIsRefused(): void
    {
        // The delay of the form (the tests of a site often ask none: base.guard.min_delay 0), given here.
        $form = self::getContainer()->get('form.factory')->create(QuoteRequestType::class, new QuoteRequestModel(), [
            'csrf_protection' => false, 'attachments' => false, 'guard' => ['action' => 'quote', 'name' => 'contactName', 'min_delay' => 5, 'challenge' => false],
        ]);
        $form->submit([
            'contactName' => 'Félix Morvan', 'email' => 'fast@example.org', 'title' => 'Enseigne drapeau',
            'request' => 'Une enseigne drapeau lumineuse pour la boutique, pose comprise.',
            'guard_website' => '', 'guard_opened' => self::getContainer()->get(FormGuard::class)->stamp(time() - 1),
        ]);

        self::assertFalse($form->isValid());
        $causes = array_map(static fn ($error) => $error->getCause(), iterator_to_array($form->getErrors(true)));
        self::assertContains(FormGuard::TOO_FAST, $causes);
    }

    public function testARequestWithoutTheCaptchasTokenIsRefused(): void
    {
        [$html] = $this->form();
        if (!str_contains($html, 'omniguard-token')) {
            self::markTestSkipped('The host application has no captcha (glitchr/omniguard and its "fixed" test gateway).');
        }
        $before = $this->entityManager->getRepository(Quote::class)->count([]);
        $response = $this->send(['email' => 'nocaptcha@example.org'], [], null);

        self::assertSame(422, $response->getStatusCode());
        self::assertSame($before, $this->entityManager->getRepository(Quote::class)->count([]));
    }

    public function testAFileOfAnotherKindIsRefusedOnItsField(): void
    {
        $before = $this->entityManager->getRepository(Quote::class)->count([]);
        $response = $this->send(['email' => 'php@example.org'], [$this->upload('shell.php', '<?php')]);

        self::assertSame(422, $response->getStatusCode());
        self::assertSame($before, $this->entityManager->getRepository(Quote::class)->count([]));
    }
}
