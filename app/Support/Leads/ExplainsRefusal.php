<?php

namespace App\Support\Leads;

use App\Models\Lead;

/**
 * Why this particular lead cannot be replied to, in words for the screen.
 *
 * Optional, and deliberately a SECOND interface rather than a method on
 * [LeadReplyChannel]: a channel that has nothing special to say should not be
 * made to write a sentence, and adding a method to the main interface would
 * break every implementation that already exists — including ones another
 * module owns.
 *
 * It exists because the sentence was hard-coded in two places, and both of
 * them said "Google". On 2026-09-10 the first real Thumbtack enquiry arrived
 * and the thread told Jose that "Google only accepts replies to text
 * enquiries. This one came in as a call" — about a Thumbtack message, on a
 * lead Google had never heard of. A reason belongs to the channel that
 * refuses, or it ends up being one source's reason shown for all of them.
 */
interface ExplainsRefusal
{
    /** Only called when canReply() is false. */
    public function whyNot(Lead $lead): string;
}
