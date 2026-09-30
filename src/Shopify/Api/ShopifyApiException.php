<?php

namespace Base\Marketplace\Shopify\Api;

/**
 * Shopify said no, or said nothing.
 *
 * Carries the userErrors/errors it came with, so a command can print them and
 * a gateway can turn them into a refusal. Never carries the access token: the
 * headers are built at request time and deliberately kept out of here.
 */
class ShopifyApiException extends \RuntimeException
{
    public function __construct(string $message, public readonly array $errors = [], ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }

    /** @param array<array{message?: string, field?: array|null}> $errors */
    public static function fromErrors(string $what, array $errors): self
    {
        $messages = [];
        foreach ($errors as $error) {
            $field = $error['field'] ?? null;
            $messages[] = ($field ? implode('.', (array) $field) . ': ' : '') . ($error['message'] ?? 'unknown error');
        }

        return new self($what . ': ' . implode('; ', $messages ?: ['unknown error']), $errors);
    }
}
