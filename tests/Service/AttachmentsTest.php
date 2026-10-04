<?php

namespace Tests\Base\Marketplace\Service;

use Base\Marketplace\Entity\Attachment;
use Base\Marketplace\Entity\Order\OrderItem;
use Base\Marketplace\Entity\Product;
use Base\Marketplace\Entity\Quote;
use Base\Marketplace\Service\Attachments;
use Base\Marketplace\Service\CartException;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\UriSigner;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/** Files given with a quote request or an order line: kept out of the public directory, limited, downloaded. */
final class AttachmentsTest extends TestCase
{
    private string $directory;
    private string $incoming;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/marketplace-attachments-'.bin2hex(random_bytes(4));
        $this->incoming = $this->directory.'-in';
        mkdir($this->incoming, 0777, true);
    }

    protected function tearDown(): void
    {
        foreach ([$this->directory, $this->incoming] as $root) {
            if (!is_dir($root)) {
                continue;
            }
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($files as $file) {
                $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
            }
            rmdir($root);
        }
    }

    private function attachments(int $maxSize = 1024, int $maxFiles = 2): Attachments
    {
        $router = $this->createMock(UrlGeneratorInterface::class);
        $router->method('generate')->willReturnCallback(fn (string $name, array $parameters) => 'https://shop.test/marketplace/piece-jointe/'.($parameters['id'] ?? 0));

        return new Attachments($this->createMock(EntityManagerInterface::class), new UriSigner('secret'), $router, $this->directory, $maxSize, $maxFiles, ['pdf', 'png', 'svg']);
    }

    private function upload(string $name, string $content = '%PDF-1.4 logo'): UploadedFile
    {
        $path = $this->incoming.'/'.bin2hex(random_bytes(4));
        file_put_contents($path, $content);

        // test: true - moved with rename(), as no HTTP upload happened.
        return new UploadedFile($path, $name, null, null, true);
    }

    public function testAQuotesFilesAreKeptUnderARandomNameAndDownloadedUnderTheirOwn(): void
    {
        $attachments = $this->attachments();
        $quote = new Quote('D-2026-0001');
        $stored = $attachments->attachToQuote($quote, [$this->upload('Logo façade.pdf'), 'not a file']);

        self::assertCount(1, $stored);
        self::assertCount(1, $quote->getAttachments());
        $attachment = $stored[0];
        self::assertSame($quote, $attachment->getQuote());
        self::assertSame('Logo façade.pdf', $attachment->getName());
        self::assertMatchesRegularExpression('#^quote/\d{4}/\d{2}/[0-9a-f]{32}\.pdf$#', $attachment->getPath());
        self::assertFileExists($attachments->file($attachment));
        self::assertStringStartsWith(realpath($this->directory), $attachments->file($attachment));

        $response = $attachments->download($attachment);
        self::assertStringContainsString('attachment;', $response->headers->get('Content-Disposition'));
        self::assertStringContainsString("filename*=utf-8''Logo%20fa%C3%A7ade.pdf", $response->headers->get('Content-Disposition'));
        self::assertSame('application/octet-stream', $response->headers->get('Content-Type'), 'never shown in the page: an SVG may carry a script');
        self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
    }

    public function testAnOrderLinesArtwork(): void
    {
        $product = $this->getMockBuilder(Product::class)->disableOriginalConstructor()->onlyMethods(['__toString', 'getReference'])->getMock();
        $product->method('__toString')->willReturn('Roll-up');
        $product->method('getReference')->willReturn('ROL0001');
        (new \ReflectionProperty(Product::class, 'unitPrice'))->setValue($product, 13000);
        (new \ReflectionProperty(Product::class, 'currency'))->setValue($product, 'EUR');
        $line = new OrderItem($product, 1);

        $stored = $this->attachments()->attachToItem($line, [$this->upload('rollup.svg', '<svg/>')]);
        self::assertSame($line, $stored[0]->getOrderItem());
        self::assertCount(1, $line->getAttachments());
        self::assertStringStartsWith('order/', $stored[0]->getPath());
    }

    public function testTooMany(): void
    {
        $this->expectException(CartException::class);
        $this->expectExceptionMessage('attachment.error.count');
        $this->attachments()->attachToQuote(new Quote('D-1'), [$this->upload('a.pdf'), $this->upload('b.pdf'), $this->upload('c.pdf')]);
    }

    public function testTooHeavyOrOfAnotherKind(): void
    {
        $attachments = $this->attachments(maxSize: 16);
        self::assertSame('attachment.error.size', $attachments->refusal([$this->upload('plan.pdf', str_repeat('x', 64))])[0]);
        self::assertSame('attachment.error.type', $attachments->refusal([$this->upload('run.php', '<?php')])[0]);
        self::assertNull($attachments->refusal([$this->upload('ok.png', 'x')]));
        self::assertSame('attachment.error.count', $attachments->refusal([$this->upload('ok.png', 'x')], 2)[0], 'with those the line already has');
    }

    public function testAPathOutOfTheDirectoryIsNoFile(): void
    {
        $attachments = $this->attachments();
        mkdir($this->directory, 0777, true);
        file_put_contents($this->incoming.'/secret.txt', 'x');
        $outside = new Attachment('../'.basename($this->incoming).'/secret.txt', 'secret.txt');

        self::assertNull($attachments->file($outside));
    }

    public function testASignedLinkForSomebodyWithoutAnAccount(): void
    {
        $attachment = new Attachment('order/2026/10/a.pdf', 'a.pdf');
        (new \ReflectionProperty(Attachment::class, 'id'))->setValue($attachment, 7);
        $url = $this->attachments()->url($attachment, 60);

        self::assertStringStartsWith('https://shop.test/marketplace/piece-jointe/7?', $url);
        self::assertTrue((new UriSigner('secret'))->check($url));
        self::assertFalse((new UriSigner('secret'))->check(str_replace('/7?', '/8?', $url)));
    }
}
