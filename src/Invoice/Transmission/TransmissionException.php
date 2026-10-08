<?php

namespace Base\Marketplace\Invoice\Transmission;

/**
 * A gateway of glitchr/omnibill refused, did not answer, or is not set up:
 * told to the back office as any refusal (a LogicException), its cause
 * kept as the previous exception.
 */
class TransmissionException extends \LogicException
{
}
