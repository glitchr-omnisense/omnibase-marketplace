<?php

namespace Base\Marketplace\Service;

use Base\Marketplace\Entity\Attachment;
use Base\Marketplace\Entity\Order\OrderItem;
use Base\Marketplace\Entity\Quote;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\UriSigner;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * The files given with a quote request or for an order line
 * (Entity\Attachment): stored outside the public directory under a random
 * name, limited in number, weight and kind, served as downloads only - to
 * the back office, or to whoever holds a signed link (url(): what a
 * supplier is given to fetch an artwork).
 */
class Attachments
{
    /** @param list<string> $extensions */
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UriSigner $signer,
        private readonly UrlGeneratorInterface $router,
        #[Autowire('%marketplace.attachments.directory%')] private readonly string $directory,
        #[Autowire('%marketplace.attachments.max_size%')] private readonly int $maxSize = 20971520,
        #[Autowire('%marketplace.attachments.max_files%')] private readonly int $maxFiles = 5,
        #[Autowire('%marketplace.attachments.extensions%')] private readonly array $extensions = ['pdf', 'png', 'jpg', 'jpeg', 'webp', 'gif', 'svg', 'ai', 'eps', 'psd', 'tif', 'tiff', 'zip'],
    ) {
    }

    public function maxFiles(): int
    {
        return $this->maxFiles;
    }

    public function maxSize(): int
    {
        return $this->maxSize;
    }

    /** @return list<string> */
    public function extensions(): array
    {
        return $this->extensions;
    }

    /**
     * Why these files cannot be taken, as a key of the marketplace
     * translations and its parameters - or null when they can.
     *
     * @param iterable<UploadedFile> $files
     *
     * @return array{0: string, 1: array<string, string|int>}|null
     */
    public function refusal(iterable $files, int $already = 0): ?array
    {
        $count = $already;
        foreach ($files as $file) {
            if (!$file instanceof UploadedFile) {
                continue;
            }
            if (++$count > $this->maxFiles) {
                return ['attachment.error.count', ['{maximum}' => $this->maxFiles]];
            }
            if (!$file->isValid()) {
                return ['attachment.error.upload', ['{name}' => $file->getClientOriginalName()]];
            }
            if ($file->getSize() > $this->maxSize) {
                return ['attachment.error.size', ['{name}' => $file->getClientOriginalName(), '{maximum}' => (int) round($this->maxSize / 1048576)]];
            }
            if (!\in_array($this->extension($file), $this->extensions, true)) {
                return ['attachment.error.type', ['{name}' => $file->getClientOriginalName(), '{types}' => implode(', ', $this->extensions)]];
            }
        }

        return null;
    }

    /**
     * Keeps the files of a quote request.
     *
     * @param iterable<UploadedFile> $files
     *
     * @return list<Attachment>
     *
     * @throws CartException attachment.error.*
     */
    public function attachToQuote(Quote $quote, iterable $files): array
    {
        return $this->store($files, 'quote', $quote->getAttachments()->count(), function (Attachment $attachment) use ($quote): void {
            $quote->addAttachment($attachment);
        });
    }

    /**
     * Keeps the files of an order line: the buyer's artwork.
     *
     * @param iterable<UploadedFile> $files
     *
     * @return list<Attachment>
     *
     * @throws CartException attachment.error.*
     */
    public function attachToItem(OrderItem $item, iterable $files): array
    {
        return $this->store($files, 'order', $item->getAttachments()->count(), function (Attachment $attachment) use ($item): void {
            $attachment->setOrderItem($item);
            $item->getAttachments()->add($attachment);
        });
    }

    /** The file on disk; null when it is gone. */
    public function file(Attachment $attachment): ?string
    {
        $path = $this->directory.'/'.$attachment->getPath();
        $real = realpath($path);
        $root = realpath($this->directory);

        return $real && $root && str_starts_with($real, $root.\DIRECTORY_SEPARATOR) && is_file($real) ? $real : null;
    }

    /** A download: never shown in the page (an SVG may carry a script), named as the sender named it. */
    public function download(Attachment $attachment): BinaryFileResponse
    {
        $file = $this->file($attachment) ?? throw new \RuntimeException(sprintf('The file of attachment %d is missing.', $attachment->getId()));
        $response = new BinaryFileResponse($file);
        $fallback = preg_replace('/[^A-Za-z0-9._-]+/', '_', $attachment->getName()) ?: 'file';
        $response->headers->set('Content-Disposition', HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, str_replace(['/', '\\', '%'], '_', $attachment->getName()), $fallback));
        $response->headers->set('Content-Type', 'application/octet-stream');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->setPrivate();

        return $response;
    }

    /** A link good for $ttl seconds, for somebody without an account: a supplier fetching an artwork. */
    public function url(Attachment $attachment, int $ttl = 604800): string
    {
        $url = $this->router->generate('marketplace_attachment', ['id' => $attachment->getId()], UrlGeneratorInterface::ABSOLUTE_URL);

        return $this->signer->sign($url, new \DateTimeImmutable(sprintf('+%d seconds', $ttl)));
    }

    public function remove(Attachment $attachment): void
    {
        if ($file = $this->file($attachment)) {
            @unlink($file);
        }
        $this->entityManager->remove($attachment);
    }

    /**
     * @param iterable<UploadedFile> $files
     *
     * @return list<Attachment>
     */
    private function store(iterable $files, string $kind, int $already, callable $attach): array
    {
        $files = array_values(array_filter([...$files], fn ($file) => $file instanceof UploadedFile));
        if ($refusal = $this->refusal($files, $already)) {
            throw new CartException(...$refusal);
        }

        $stored = [];
        foreach ($files as $file) {
            $folder = sprintf('%s/%s', $kind, date('Y/m'));
            $name = bin2hex(random_bytes(16)).'.'.$this->extension($file);
            $size = (int) $file->getSize();
            $mime = (string) ($file->getMimeType() ?: 'application/octet-stream');
            $original = $file->getClientOriginalName();
            $file->move($this->directory.'/'.$folder, $name);

            $attachment = new Attachment($folder.'/'.$name, $original, $mime, $size);
            $attach($attachment);
            $this->entityManager->persist($attachment);
            $stored[] = $attachment;
        }

        return $stored;
    }

    private function extension(UploadedFile $file): string
    {
        return strtolower(pathinfo($file->getClientOriginalName(), \PATHINFO_EXTENSION));
    }
}
