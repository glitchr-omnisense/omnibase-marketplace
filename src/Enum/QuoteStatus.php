<?php

namespace Base\Marketplace\Enum;

/**
 * A quote's life, the same for every trade (a négociant's export offer, a
 * studio's hours):
 *
 * requested  a visitor asked for one (the quote form); nothing priced yet
 * draft      the seller is writing the lines
 * sent       the client can read and accept it
 * accepted   the client said yes: an order waits for payment
 * paid       the order was paid
 * declined   the client said no, or it lapsed
 */
enum QuoteStatus: string
{
    case REQUESTED = 'requested';
    case DRAFT = 'draft';
    case SENT = 'sent';
    case ACCEPTED = 'accepted';
    case PAID = 'paid';
    case DECLINED = 'declined';

    public function isOpenToClient(): bool
    {
        return self::SENT === $this;
    }

    /** The seller may still change the lines and send it (again). */
    public function isEditable(): bool
    {
        return \in_array($this, [self::REQUESTED, self::DRAFT, self::SENT], true);
    }

    /** @return list<self> in the order of the pipeline's columns */
    public static function pipeline(): array
    {
        return [self::REQUESTED, self::DRAFT, self::SENT, self::ACCEPTED, self::PAID, self::DECLINED];
    }
}
