<?php

namespace Base\Marketplace\Model;

use Base\Marketplace\Enum\Incoterm;
use Base\Marketplace\Enum\TradeDirection;
use Base\Marketplace\Validator\CompanyNumber;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * What the quote form collects. A DTO, not the Quote itself: omnibase's form
 * factory refuses an entity as form data (a half-filled entity in the unit
 * of work is a flush waiting to happen); the controller turns it into a
 * Quote once it is valid.
 */
final class QuoteRequest
{
    #[Assert\NotBlank, Assert\Length(max: 128)]
    public string $contactName = '';

    #[Assert\NotBlank, Assert\Email, Assert\Length(max: 180)]
    public string $email = '';

    /** A number to be called back on. */
    #[Assert\Length(max: 32), Assert\Regex(pattern: '/^[0-9+().\s\-]{6,}$/', message: 'quote.phone_invalid')]
    public ?string $phone = null;

    #[Assert\Length(max: 180)]
    public ?string $companyName = null;

    /** A French company's SIREN or SIRET - checked against the State's register. */
    #[CompanyNumber]
    public ?string $siret = null;

    /** An EU company's VAT number - checked against VIES. */
    #[Assert\Length(max: 20)]
    public ?string $vatNumber = null;

    #[Assert\NotBlank, Assert\Length(max: 180)]
    public string $title = '';

    public ?TradeDirection $direction = null;

    public ?Incoterm $incoterm = null;

    #[Assert\Country]
    public ?string $country = null;

    #[Assert\Length(max: 180)]
    public ?string $place = null;

    #[Assert\Length(max: 255)]
    public ?string $volume = null;

    #[Assert\GreaterThanOrEqual('today')]
    public ?\DateTimeImmutable $targetDate = null;

    #[Assert\Length(max: 1000)]
    public ?string $deliveryAddress = null;

    #[Assert\NotBlank, Assert\Length(min: 20, max: 6000)]
    public string $request = '';

    /** The products the request starts from (ids), when it was asked from their pages. */
    public array $products = [];

    /** @var list<\Symfony\Component\HttpFoundation\File\UploadedFile> a logo, a photo of the place, a plan: checked by Service\Attachments */
    public array $files = [];
}
