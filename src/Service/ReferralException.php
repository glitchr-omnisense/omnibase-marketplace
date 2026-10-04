<?php

namespace Base\Marketplace\Service;

/** A referral that cannot be: its own code, an unknown one, a member already referred. The reason is its message. */
class ReferralException extends \RuntimeException
{
}
