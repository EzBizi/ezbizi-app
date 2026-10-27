<?php

namespace App\Console\Commands;

use App\Models\Customer;
use App\Models\Job;
use App\Models\Lead;
use App\Models\Mailbox;
use App\Models\Organization;
use App\Models\WorkizJob;
use App\Support\Email\MailboxReaders;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Which job each lead became, found by the customer's phone number.
 *
 * A1 Leads matches Local Services leads to Workiz jobs to put revenue beside
 * each account's spend; this is EzBizi's version (Jose, 2026-09-28: "build
 * everything and adapt it to ezbizi"). A lead whose phone matches an EzBizi
 * customer with a job BOOKED from a day before the lead to 30 days after it is
 * given that job - the earliest one. EzBizi's customers and jobs, not Workiz's
 * clients: EzBizi is the master and Workiz a mirror (Jose, 2026-10-27). Measured on production the same day: 2,720
 * of the last 90 days' 6,533 Local Services leads match, and every one of those
 * jobs is already an EzBizi job.
 *
 * Only a lead with no job is touched, so a job somebody booked from the lead
 * itself is never replaced, and `job_matched_by` records that this link was
 * found rather than made - the lead's own status is left alone.
 *
 * Booked is the job's created_at, except where Workiz's mirror holds its
 * booked_at: a projected job's created_at is the moment it was imported.
 */
class MatchLeadsToJobs extends Command
{
    protected $signature = 'ezbizi:match-leads-to-jobs {--days=45 : how far back to look for unmatched leads} {--all : every unmatched lead}';

    protected $description = 'Link leads to the Workiz job they became, by the customer\'s phone number';

    private const BEFORE = 86400;          // a job booked the day before the lead came in still counts

    private const AFTER = 30 * 86400;

    public function handle(TenantContext $tenant): int
    {
        $since = $this->option('all') ? null : now()->subDays((int) $this->option('days'));
        $matched = $this->fillGoogleBookings($tenant, $since);
        $matched += $this->fillFromBookingEmails($tenant, $since);

        $organizations = Lead::query()->withoutGlobalScopes()
            ->whereNull('job_id')->whereNotNull('phone_digits')
            ->when($since, fn ($q) => $q->where('received_at', '>=', $since))
            ->distinct()->pluck('organization_id');

        foreach (Organization::query()->withoutGlobalScopes()->whereIn('id', $organizations)->get() as $organization) {
            $tenant->run($organization, function () use ($since, &$matched) {
                // EzBizi is the master; Workiz a mirror (Jose, 2026-10-27). So
                // EzBizi's own customers by the last ten digits, and their jobs
                // by customer: one read each, not one per lead.
                $clients = Customer::query()->whereNotNull('phone')->get(['id', 'phone'])
                    ->groupBy(fn (Customer $c) => substr((string) preg_replace('/\D/', '', (string) $c->phone), -10));
                // When it was booked: a Workiz-projected job's created_at is the
                // moment it was imported, so its mirror's booked_at wins where
                // there is one. Without Workiz, created_at is the booking.
                $jobs = Job::query()->whereNotNull('customer_id')
                    ->select(['id', 'customer_id', 'created_at'])
                    ->selectSub(WorkizJob::query()->selectRaw('MIN(booked_at)')->whereColumn('workiz_jobs.job_id', 'jobs.id'), 'mirror_booked_at')
                    ->get()
                    ->map(fn (Job $j) => (object) ['job_id' => $j->id, 'customer_id' => $j->customer_id,
                        'booked_at' => $j->mirror_booked_at !== null ? Carbon::parse($j->mirror_booked_at) : $j->created_at])
                    ->groupBy('customer_id');

                Lead::query()
                    ->whereNull('job_id')->whereNotNull('phone_digits')->whereNotNull('received_at')
                    ->when($since, fn ($q) => $q->where('received_at', '>=', $since))
                    ->lazyById(500)
                    ->each(function (Lead $lead) use ($clients, $jobs, &$matched) {
                        $digits = substr($lead->phone_digits, -10);
                        if (strlen($digits) < 10) {
                            return;
                        }
                        $at = $lead->received_at->getTimestamp();
                        $job = $clients->get($digits, collect())
                            ->flatMap(fn (Customer $c) => $jobs->get($c->id, collect()))
                            ->filter(fn (object $j) => ($t = $j->booked_at->getTimestamp()) >= $at - self::BEFORE && $t <= $at + self::AFTER)
                            ->sortBy('booked_at')
                            ->first();

                        if ($job !== null) {
                            $lead->forceFill([
                                'job_id' => $job->job_id,
                                'job_matched_by' => 'phone',
                                // It became a job: nobody owes it an answer
                                // (Jose, 2026-10-09). A decision already made
                                // (booked, archived, not a lead) stands.
                                'status' => $lead->status === Lead::STATUS_NEW ? Lead::STATUS_COMPLETED : $lead->status,
                            ])->save();
                            $matched++;
                        }
                    });
            });
        }

        $this->info("Matched {$matched} lead(s) to a job.");

        return self::SUCCESS;
    }

    /**
     * A Local Services BOOKING arrives from Google with no customer at all
     * (655 of 655, measured 2026-10-09). The same booking lands in Workiz as a
     * "Reserve with Google" job within a second or two, with the name, phone
     * and address (Jose, 2026-10-09: "is there anyway to get information on
     * these?"). Workiz's booked_at is the branch's wall clock, so the two
     * instants differ by a whole number of hours; matched to within two
     * minutes of one, and only when exactly one job fits.
     */
    private function fillGoogleBookings(TenantContext $tenant, ?Carbon $since): int
    {
        $filled = 0;
        $orgs = Lead::query()->withoutGlobalScopes()
            ->where('source', Lead::SOURCE_LOCAL_SERVICES)->where('lead_type', 'BOOKING')->whereNull('phone')
            ->when($since, fn ($q) => $q->where('received_at', '>=', $since))
            ->distinct()->pluck('organization_id');

        foreach (Organization::query()->withoutGlobalScopes()->whereIn('id', $orgs)->get() as $organization) {
            $tenant->run($organization, function () use ($since, &$filled) {
                $leads = Lead::query()->where('source', Lead::SOURCE_LOCAL_SERVICES)->where('lead_type', 'BOOKING')
                    ->whereNull('phone')->whereNotNull('received_at')
                    ->when($since, fn ($q) => $q->where('received_at', '>=', $since))->get();
                if ($leads->isEmpty()) {
                    return;
                }
                $jobs = WorkizJob::query()->where('job_source', 'Reserve with Google')
                    ->where('booked_at', '>=', $leads->min('received_at')->copy()->subDay())->get();

                foreach ($leads as $lead) {
                    $at = $lead->received_at->getTimestamp();
                    $fits = $jobs->filter(function (WorkizJob $job) use ($at) {
                        $wall = Carbon::parse((string) $job->getRawOriginal('booked_at'), 'UTC')->getTimestamp();
                        $hours = round(($at - $wall) / 3600);

                        return $hours >= 0 && $hours <= 12 && abs($at - $wall - $hours * 3600) <= 120;
                    });
                    if ($fits->count() !== 1) {
                        continue;
                    }
                    $job = $fits->first();
                    $p = (array) $job->payload;
                    $phone = Lead::e164($p['Phone'] ?? null);
                    $lead->forceFill([
                        'contact_name' => $lead->contact_name ?: (trim(($p['FirstName'] ?? '').' '.($p['LastName'] ?? '')) ?: null),
                        'phone' => $phone,
                        'phone_digits' => Lead::digits($phone),
                        'email' => $lead->email ?: (trim((string) ($p['Email'] ?? '')) ?: null),
                        'postal_code' => $lead->postal_code ?: $job->postal_code,
                        'job_id' => $lead->job_id ?? $job->job_id,
                        'job_matched_by' => $lead->job_matched_by ?? 'google',
                        'status' => $lead->status === Lead::STATUS_NEW ? Lead::STATUS_COMPLETED : $lead->status,
                    ])->save();
                    $filled++;
                }
            });
        }

        return $filled;
    }

    /**
     * What Workiz's job data did not give, its "New online booking" email does
     * (Jose, 2026-10-13: "look in other emails for the missing ones").
     * Measured that day: 50 of the last fortnight's unnamed bookings had one
     * in the branch's office inbox within 1-2 seconds of Google's booking,
     * carrying "From: <name> Phone: ... Email: ... Address: ... When:".
     * Paired within ten seconds, each email once; only blanks are filled.
     */
    private function fillFromBookingEmails(TenantContext $tenant, ?Carbon $since): int
    {
        $filled = 0;
        $readers = app(MailboxReaders::class);
        $days = $since === null ? 365 : max(1, (int) ceil($since->diffInDays(now())) + 1);

        $orgs = Lead::query()->withoutGlobalScopes()
            ->where('source', Lead::SOURCE_LOCAL_SERVICES)->where('lead_type', 'BOOKING')->whereNull('phone')
            ->when($since, fn ($q) => $q->where('received_at', '>=', $since))
            ->distinct()->pluck('organization_id');

        foreach (Organization::query()->withoutGlobalScopes()->whereIn('id', $orgs)->get() as $organization) {
            $tenant->run($organization, function () use ($readers, $days, $since, &$filled) {
                $emails = [];
                foreach (Mailbox::query()->where('status', Mailbox::STATUS_CONNECTED)->get() as $mailbox) {
                    $cursor = null;
                    // ponytail: 20 pages of 100 per inbox; an --all past that keeps the rest unnamed.
                    for ($pages = 0; $pages < 20; $pages++) {
                        try {
                            $page = $readers->for($mailbox)->search($mailbox, 'from:notifications@mg.workiz.com "New online booking" newer_than:'.$days.'d', $cursor, 100);
                        } catch (Throwable) {
                            break;   // one mailbox that will not answer is not the others' problem
                        }
                        foreach ($page->messages as $m) {
                            if ($m->sentAt !== null) {
                                $emails[] = ['at' => $m->sentAt->getTimestamp(), 'text' => html_entity_decode($m->snippet, ENT_QUOTES)];
                            }
                        }
                        if (($cursor = $page->nextCursor) === null) {
                            break;
                        }
                    }
                }
                if ($emails === []) {
                    return;
                }

                $leads = Lead::query()->where('source', Lead::SOURCE_LOCAL_SERVICES)->where('lead_type', 'BOOKING')
                    ->whereNull('phone')->whereNotNull('received_at')
                    ->when($since, fn ($q) => $q->where('received_at', '>=', $since))->get();

                foreach ($leads as $lead) {
                    $at = $lead->received_at->getTimestamp();
                    $best = null;
                    foreach ($emails as $i => $e) {
                        if (abs($e['at'] - $at) <= 10 && ($best === null || abs($e['at'] - $at) < abs($emails[$best]['at'] - $at))) {
                            $best = $i;
                        }
                    }
                    if ($best === null) {
                        continue;
                    }
                    $about = self::bookingEmail($emails[$best]['text']);
                    unset($emails[$best]);
                    if ($about['phone'] === null && $about['name'] === null) {
                        continue;
                    }
                    $lead->forceFill([
                        'contact_name' => $lead->contact_name ?: $about['name'],
                        'phone' => $about['phone'],
                        'phone_digits' => Lead::digits($about['phone']),
                        'email' => $lead->email ?: $about['email'],
                        'postal_code' => $lead->postal_code ?: $about['zip'],
                    ])->save();
                    $filled++;
                }
            });
        }

        return $filled;
    }

    /**
     * "You got a new service request From: Mary Ciccolini Phone: (330) 606-3475
     * Email: m@x.com Address: 2585 royalwood rd copley, Ohio 44203 When: ..."
     *
     * @return array{name: ?string, phone: ?string, email: ?string, zip: ?string}
     */
    public static function bookingEmail(string $text): array
    {
        $field = function (string $label, string $next) use ($text): ?string {
            return preg_match('/'.$label.':\s*(.+?)\s*(?:'.$next.':|$)/u', $text, $m) === 1 && trim($m[1]) !== '' ? trim($m[1]) : null;
        };
        $address = $field('Address', 'When');

        return [
            'name' => $field('From', 'Phone'),
            'phone' => Lead::e164($field('Phone', 'Email')),
            'email' => filter_var((string) $field('Email', 'Address'), FILTER_VALIDATE_EMAIL) ?: null,
            'zip' => $address !== null && preg_match('/\b(\d{5})(?:-\d{4})?\s*$/', $address, $z) === 1 ? $z[1] : null,
        ];
    }

}
