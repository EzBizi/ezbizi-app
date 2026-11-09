<?php

namespace App\Support\Leads;

use App\Models\Lead;
use Illuminate\Support\Carbon;

/**
 * A channel that only accepts a reply for a while after the person last wrote
 * (Messenger, Instagram and WhatsApp: 24 hours). Optional, beside
 * [ExplainsRefusal], so the screen can say how long is left rather than only
 * that it has run out.
 */
interface HasReplyWindow
{
    /** When the window closes; null when the person has not written at all. */
    public function replyWindowEndsAt(Lead $lead): ?Carbon;
}
