<?php

namespace Base\Market\Service;

/** Why the exchange rates were not refreshed: no key, too soon, the provider's refusal... */
final class ExchangeRatesException extends \RuntimeException
{
}
