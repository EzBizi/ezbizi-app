<?php

namespace App\Support\Phone;

use App\Models\PhoneNumber;

/**
 * Outbound SMS is blocked until the line's campaign (or toll-free check) is
 * approved. Settings flips sms_outbound_allowed; this gate only reads it.
 */
final class SmsCompliance
{
    public const REFUSAL = 'This line cannot send texts until messaging is approved.';

    public function allowsOutbound(PhoneNumber $number): bool
    {
        return (bool) $number->sms_outbound_allowed;
    }
}
