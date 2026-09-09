<?php

namespace App\Support\Social;

use App\Models\Job;
use App\Models\Lead;
use App\Support\Settings\SettingsResolver;

/**
 * What a job's status means to Thumbtack, if anything.
 *
 * ── Why this is a setting and not a table ────────────────────────
 *
 * Thumbtack's six statuses are fixed. EzBizi's are not: every organization
 * writes its own vocabulary in `jobs.statuses`, so one company's "Done" is
 * another's "Closed out" and a third's "Complete — invoiced". No table here
 * could map those without guessing at words.
 *
 * And a guess would not be a display bug. `POST .../job-status` is a claim
 * about a job made to the platform that measures the Pro on it, so reporting
 * "job_complete" for a status that actually means "quote sent" damages the
 * business we are supposed to be helping. The tenant is the only party that
 * knows, so the tenant says — `jobs.thumbtack_status_map`.
 *
 * ── Empty means silent, deliberately ─────────────────────────────
 *
 * The default is `[]`, so a business that never opens the setting reports
 * nothing at all. That is the same choice `EarnedStatuses` makes for revenue,
 * for the same reason: an unconfigured map is EzBizi not knowing, and not
 * knowing should look like silence rather than like a confident wrong answer.
 *
 * ── Intersected with what the organization publishes ─────────────
 *
 * A status renamed or removed in `jobs.statuses` must stop meaning anything
 * here, rather than keeping a mapping nothing can reach. Same guard, same
 * reason as EarnedStatuses::for().
 */
final class ThumbtackJobStatus
{
    /**
     * The Thumbtack status this job's own status maps to, or null.
     *
     * Null covers every "do not report" case and they are not distinguished on
     * purpose: an unmapped status, a map naming a status the organization no
     * longer publishes, and a map naming something that is not a Thumbtack
     * status all mean the same thing to the caller — say nothing.
     */
    public static function forJob(Job $job): ?string
    {
        $status = trim((string) $job->status);

        if ($status === '' || $job->franchise === null) {
            return null;
        }

        $map = app(SettingsResolver::class)->get('jobs.thumbtack_status_map', $job->franchise);

        if (! is_array($map)) {
            return null;
        }

        $theirs = $map[$status] ?? null;

        if (! is_string($theirs) || ! in_array($theirs, ThumbtackNegotiations::STATUSES, true)) {
            // A value that is not one of their six. Refused here rather than
            // sent, because their refusal names the field and not the value —
            // it would read as our bug with no clue which status to fix.
            return null;
        }

        // The status must still be one the organization publishes. A renamed
        // status leaves a stale key behind in the map, and honouring it would
        // report a meaning nobody can see in the settings screen any more.
        if (! in_array($status, Job::allowedStatuses($job->franchise), true)) {
            return null;
        }

        return $theirs;
    }

    /**
     * The Thumbtack enquiry this job came from, or null for an ordinary job.
     *
     * Most jobs are not Thumbtack's. This is the cheap check that keeps the
     * reporter from doing anything at all for them.
     */
    public static function leadFor(Job $job): ?Lead
    {
        return Lead::query()
            ->where('job_id', $job->getKey())
            ->where('source', Lead::SOURCE_THUMBTACK)
            ->whereNotNull('remote_id')
            ->first();
    }

    /**
     * The invoice total in cents, for the one status that carries money.
     *
     * Only for `invoice_paid`. Thumbtack takes the field on any status and
     * sending it elsewhere would state a figure about work that has not been
     * invoiced — a number in somebody else's system that nobody here meant to
     * publish.
     */
    public static function invoiceCentsFor(Job $job, string $thumbtackStatus): ?int
    {
        if ($thumbtackStatus !== 'invoice_paid') {
            return null;
        }

        $amount = (int) ($job->amount_minor ?? 0);

        return $amount > 0 ? $amount : null;
    }
}
