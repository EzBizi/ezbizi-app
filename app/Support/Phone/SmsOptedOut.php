<?php

namespace App\Support\Phone;

use RuntimeException;

/** The number replied STOP (here or at Twilio, 21610): honouring it is a skip, never a failure (health sweep 11-13/11-20). */
final class SmsOptedOut extends RuntimeException
{
    public function __construct()
    {
        parent::__construct(SmsConsent::REFUSAL);
    }
}
