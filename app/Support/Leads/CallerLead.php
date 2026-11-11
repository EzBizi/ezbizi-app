<?php

namespace App\Support\Leads;

use App\Models\Lead;
use App\Support\Settings\SettingsResolver;
use Carbon\CarbonImmutable;

/**
 * The lead a phone call from this person belongs on (RC Calls, EZ Calls): one
 * lead per caller.
 *
 * Their inbox lead from the last 30 days; failing that, their latest ARCHIVED
 * lead at any age, which the call reopens (Jose, 2026-10-19, asked whether a
 * call on an archived lead should reopen it: "yes of course"). So a caller
 * from the 43,118 leads archived before 2026-09-28 comes back on their own
 * lead rather than as a stranger. Only the latest one, so one call never
 * reopens a person's whole history.
 */
final class CallerLead
{
    public static function find(?string $digits, CarbonImmutable $at): ?Lead
    {
        if ($digits === null || $digits === '') {
            return null;
        }
        $theirs = fn () => Lead::query()
            ->where('phone_digits', 'like', '%'.$digits)
            ->whereIn('source', Lead::INBOX_SOURCES)
            ->where('status', '!=', Lead::STATUS_JOB_APPLICANT)
            ->orderByDesc('received_at');

        return $theirs()->where('received_at', '>=', $at->subDays((int) SettingsResolver::forOrganization('leads.same_person_days')))->first()
            ?? (($latest = $theirs()->first()) !== null && $latest->status === Lead::STATUS_ARCHIVED ? $latest : null);
    }

    /**
     * Back to New when they call in, as a message does (LeadThread). Status
     * only: a call still owes nobody a reply. Not for our own calls out, nor
     * a hang-up in the phone menu.
     */
    public static function reopen(Lead $lead): void
    {
        if ($lead->status === Lead::STATUS_ARCHIVED) {
            $lead->forceFill(['status' => Lead::STATUS_NEW])->save();
        }
    }
}
