<?php

namespace Base\Market\Model;

/**
 * A customer who may buy as a business: its intra-EU VAT number, and whether
 * VIES confirmed it (Service\VatNumbers). A confirmed number from another EU
 * country than the store's makes an order a reverse charge
 * (Pricing\ReverseCharge): no VAT on it, the buyer accounts for it.
 *
 * The app's User implements it, usually with VatCustomerTrait - the bundle
 * does not reach into an app's user hierarchy (see MerchantInterface).
 */
interface VatCustomerInterface
{
    /** Country prefix included, as VIES writes it: "DE123456789", "EL...". */
    public function getVatNumber(): ?string;

    /** A number set is not confirmed yet: markVatNumberChecked() says it is. */
    public function setVatNumber(?string $vatNumber): static;

    public function getVatNumberCheckedAt(): ?\DateTimeImmutable;

    /** VIES's consultation number for that check: the proof the number was valid that day. */
    public function getVatConsultationNumber(): ?string;

    public function markVatNumberChecked(?\DateTimeImmutable $at = null, ?string $consultationNumber = null): static;

    /** The two letters of its country, as VIES writes them ("EL" for Greece). */
    public function getVatCountry(): ?string;

    public function hasConfirmedVatNumber(): bool;
}
