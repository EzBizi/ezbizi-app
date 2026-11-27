<?php

namespace App\Support\Workiz;

use App\Models\Lead;
use App\Support\Notifications\LeadNotificationText;

/**
 * One Workiz lead payload, reduced to the `leads` row the Leads module asked
 * for.
 *
 * Written to a contract the Leads session supplied against their live table.
 * Two columns in it are deliberately absent from this class:
 *
 *  - **`source`** is theirs. They add `Lead::SOURCE_WORKIZ` and the caller
 *    references the constant, so a typo is a build error rather than a row
 *    nobody can see.
 *  - **`status`** is theirs and MUST NOT be set here. `Lead::STATUS_NEW` means
 *    "nobody at EzBizi has made a decision yet"; Workiz's own state is a
 *    different namespace that happens to share some words. It goes to
 *    `source_status` verbatim, which is what that column is for. There is a
 *    test asserting this class never emits `status`.
 *
 * Also absent, and also on purpose: `last_contact_at`, `last_contact_from` and
 * the preview are derived from conversation rows by `LeadThread`. A reader
 * setting them by hand is what corrupted the Unreplied list in September.
 *
 * And the address is dropped rather than packed into `summary`: `leads` has no
 * address columns because a lead is a person and a phone number, and an address
 * belongs to a Customer.
 */
final class WorkizLeadAttributes
{
    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public static function fromPayload(array $payload): array
    {
        $phone = WorkizValue::text($payload['Phone'] ?? null);

        return [
            // The stable id. NOT SerialId — this is the key, with source and
            // organization.
            'remote_id' => WorkizValue::text($payload['UUID'] ?? null) ?? '',

            'contact_name' => self::name($payload),
            'phone' => $phone,

            // Their normalisation, not a hand-rolled one: the search box
            // matches on exactly this.
            'phone_digits' => Lead::digits($phone),

            'email' => WorkizValue::text($payload['Email'] ?? null),
            'category' => WorkizValue::text($payload['JobType'] ?? null),

            // How the notification names it (LeadNotificationText). Workiz
            // records no intake channel, only the marketing source, and the one
            // source booked online by its nature is Reserve with Google (Jose,
            // 2026-10-06: "Reserve with Google = Online"). Every other lead is
            // plain "Workiz".
            'lead_type' => strtolower((string) preg_replace('/[^a-z0-9]/i', '', (string) ($payload['JobSource'] ?? ''))) === 'reservewithgoogle'
                ? LeadNotificationText::WORKIZ_ONLINE
                : null,
            'summary' => WorkizValue::text($payload['LeadNotes'] ?? null),

            // Workiz's state, verbatim and joined, never mapped.
            'source_status' => self::sourceStatus($payload),

            // WHEN THE LEAD ARRIVED: `CreatedDate`. `LeadDateTime` is the
            // appointment -- measured across every live lead since January, it
            // is never earlier and always a round hour -- and reading it here
            // dated an Illinois lead ten days into the future and held that
            // branch's import watermark there. It is only the fallback, for a
            // payload with no CreatedDate at all.
            //
            // The naive 'Y-m-d H:i:s' string, UNCONVERTED. `Timezone` is absent
            // from every real Workiz payload, so there is no zone here to
            // convert with — the branch's is the only one that exists, and it
            // lives on the connection. Converting inside this class would hide
            // an assumption nobody could see afterwards.
            'received_at' => WorkizValue::moment($payload['CreatedDate'] ?? null) ?? WorkizValue::moment($payload['LeadDateTime'] ?? null),

            'is_test' => false,
        ];
    }

    /**
     * FirstName + LastName, falling back to the company.
     *
     * A commercial caller often has no personal name on the record, and a lead
     * rendering as an empty string in the list is worse than one named after
     * the business that rang.
     */
    private static function name(array $payload): ?string
    {
        $first = WorkizValue::text($payload['FirstName'] ?? null);
        $last = WorkizValue::text($payload['LastName'] ?? null);

        $name = trim(implode(' ', array_filter([$first, $last])));

        return $name !== '' ? $name : WorkizValue::text($payload['Company'] ?? null);
    }

    /**
     * `Status`, with `SubStatus` when there is one. Workiz's words, unedited.
     */
    private static function sourceStatus(array $payload): ?string
    {
        $status = WorkizValue::text($payload['Status'] ?? null);
        $sub = WorkizValue::text($payload['SubStatus'] ?? null);

        if ($status === null) {
            return $sub;
        }

        return $sub === null ? $status : $status.' · '.$sub;
    }
}
