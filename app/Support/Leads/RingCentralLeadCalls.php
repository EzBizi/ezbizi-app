<?php

namespace App\Support\Leads;

use App\Models\CallReportTranscript;
use App\Models\Company;
use App\Models\Franchise;
use App\Models\Lead;
use App\Models\LeadConversation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Calls straight to the business's own lines, in Leads beside the Local
 * Services calls (Jose, 2026-10-15: "some people call our number directly if
 * they find it on website or ads, so we are missing a portion of the calls").
 *
 * ── One call, shown once ─────────────────────────────────────────
 *
 * A Local Services call is a RingCentral call too: Google forwards to the same
 * lines. Measured on production 2026-10-15 over three days: 625 of 626 LS
 * calls had a RingCentral call from the same number within 60 seconds, 627 within
 * 180 (TranscribeReportCalls uses the same 180). Such a call is LEFT OUT here,
 * and one written before Google's copy arrived is withdrawn by withdrawDuplicates().
 *
 * ── Which calls are leads ────────────────────────────────────────
 *
 * Inbound, from a 10-digit number, and not:
 *   - (not the "A1 - Existing Client" queue any more: every inbound call on a
 *     lead line shows, tagged Existing client - Jose, 2026-10-16, "some of the
 *     calls from ring central are not showing up");
 *   - a queue that is a person (a direct line), the people being everyone the
 *     day's calls rang.
 * 1,973 inbound calls in those three days: 859 New Client, 514 Existing Client,
 * 441 no queue (ended in the IVR or voicemail - a missed lead is still a lead).
 *
 * ── Whose ────────────────────────────────────────────────────────
 *
 * The line's name says the market and source ("NJ - GMB", "CHI - LS Ads",
 * "SEO - Main") or a brand ("AngiSweep", "Ace Fireplace"). A brand line goes to
 * the company of that name; every other line is A-1 Chimney's, placed in the
 * branch its market code maps to (MARKETS, read from which branch the matching
 * LS leads were in, same measurement). A market without one stays at company
 * level, as most LS leads do.
 *
 * ── One person, one lead ─────────────────────────────────────────
 *
 * A caller with an inbox lead in the last 30 days gets the call on that
 * lead's thread; otherwise a lead of its own. Never a second lead per call.
 */
final class RingCentralLeadCalls
{
    public const MATCH_SECONDS = 180;

    public const EXISTING_CLIENT_QUEUE = 'A1 - Existing Client';

    /** Days a caller's earlier lead still collects their calls. */
    private const SAME_PERSON_DAYS = 30;

    /** A-1 Chimney's market codes, by the branch the same callers' LS leads were in. */
    public const MARKETS = [
        'NJ' => 'New Jersey', 'PHL' => 'New Jersey', 'NJ-PHL' => 'New Jersey',
        'CT' => 'Connecticut',
        'CHI' => 'Illinois', 'IL' => 'Illinois',
        'CLE' => 'Ohio', 'CBUS' => 'Ohio', 'CINCY' => 'Ohio', 'OH' => 'Ohio',
        'ATL' => 'Georgia', 'GA' => 'Georgia',
        'DFW' => 'Texas', 'AUS' => 'Texas', 'SAT' => 'Texas', 'HOU' => 'Texas', 'TX' => 'Texas',
        'MD' => 'DMV', 'VA' => 'DMV', 'DC' => 'DMV', 'DMV' => 'DMV',
        'NY' => 'New York',
        'MA' => 'Massachusetts', 'BOS' => 'Massachusetts',
        'IN' => 'Nationwide', 'OR' => 'Nationwide',
    ];

    /*
     * An unanswered call, by what happened (Jose, 2026-10-16: "Menu Hang ups
     * should be excluded and also tagged appropiately as Possible Spam / 2
     * should be tagged as well as Missed Call / 3 should show a voicemail
     * tag"; corrected the same day: "not excluded from leads ... but excluded
     * from the missed call alerts"). Kept in the lead's source_status, as
     * Google's status is on LS.
     * Measured 2026-10-15: 131 hang-ups in the menu, 43 missed, 7 voicemails.
     */
    public const POSSIBLE_SPAM = 'POSSIBLE_SPAM';

    public const MISSED = 'MISSED_CALL';

    public const VOICEMAIL = 'VOICEMAIL';

    /** Answered in the "A1 - Existing Client" queue: the caller pressed "existing customer". */
    public const EXISTING_CLIENT = 'EXISTING_CLIENT';

    /** A call older than this files quietly: a catch-up run announces nothing. */
    private const ANNOUNCE_WITHIN_MINUTES = 60;

    private const WORDS = [self::POSSIBLE_SPAM => 'Possible spam', self::MISSED => 'Missed call', self::VOICEMAIL => 'Voicemail'];

    public function __construct(
        private readonly LeadThread $thread,
        private readonly \App\Support\Automations\EventEmitter $events,
    ) {}

    /**
     * Lead calls from one batch of RingCentral call-log rows (RingCentralCallLog
     * shape), with the account's line names. Returns how many calls were filed.
     *
     * @param  list<array<string, mixed>>  $calls
     * @param  array<string, string>  $lineNames  E.164 => name
     */
    public function file(array $calls, array $lineNames, string $defaultCompanyId): int
    {
        $staff = collect($calls)->pluck('rang')->flatten()->filter()->unique()->flip();
        $calls = array_values(array_filter($calls, fn (array $c) => self::isLeadCall($c, $staff->all())));
        if ($calls === []) {
            return 0;
        }

        $times = array_map(fn ($c) => CarbonImmutable::parse((string) $c['started_at']), $calls);
        $ls = $this->lsCalls(min($times), max($times));
        $have = LeadConversation::query()
            ->whereIn('remote_id', array_map(fn ($c) => 'rc_'.$c['id'], $calls))
            ->pluck('remote_id')->flip();
        $companies = Company::query()->pluck('name', 'id');
        $franchises = Franchise::query()->where('company_id', $defaultCompanyId)->pluck('id', 'name');

        $filed = 0;
        foreach ($calls as $i => $call) {
            $digits = self::ten($call['from']);
            if (isset($have['rc_'.$call['id']]) || self::matches($ls[$digits] ?? [], $times[$i])) {
                continue;
            }

            $line = (string) ($lineNames[(string) $call['to_number']] ?? '');
            $companyId = self::companyFor($line, $companies->all()) ?? $defaultCompanyId;
            $franchiseId = $companyId === $defaultCompanyId
                ? ($franchises[self::MARKETS[self::market($line)] ?? ''] ?? null)
                : null;

            $outcome = self::outcome($call);
            DB::transaction(function () use ($call, $digits, $line, $companyId, $franchiseId, $times, $i, $outcome) {
                $lead = $this->leadFor($digits, $times[$i]) ?? Lead::create([
                    'organization_id' => app(\App\Support\Tenancy\TenantContext::class)->idOrFail(),
                    'company_id' => $companyId,
                    'franchise_id' => $franchiseId,
                    'source' => Lead::SOURCE_RINGCENTRAL,
                    'remote_id' => 'rc_'.$call['id'],
                    'lead_type' => 'PHONE_CALL',
                    'category' => $line !== '' ? mb_substr($line, 0, 191) : null,
                    'phone' => Lead::e164((string) $call['from']),
                    'phone_digits' => Lead::digits((string) $call['from']),
                    'status' => Lead::STATUS_NEW,
                    'received_at' => $times[$i],
                    'source_status' => $outcome,
                ]);
                // A caller who got through (or left word) is no longer a menu
                // hang-up; a later hang-up never relabels a real call.
                if ($lead->source === Lead::SOURCE_RINGCENTRAL && $lead->source_status !== $outcome && $outcome !== self::POSSIBLE_SPAM) {
                    $lead->forceFill(['source_status' => $outcome])->save();
                }

                $this->thread->store($lead, 'rc_'.$call['id'], [
                    'channel' => LeadConversation::CHANNEL_PHONE_CALL,
                    'participant' => LeadConversation::FROM_CONSUMER,
                    'occurred_at' => $times[$i],
                    // Null when nobody picked up: "rang out", as on a Google call.
                    'duration_ms' => $call['talk_seconds'] === null ? null : (int) $call['talk_seconds'] * 1000,
                    'recording_url' => $call['recording_id'] !== null ? 'ringcentral:'.$call['recording_id'] : null,
                    // What happened, said on the card instead of "0:00".
                    'text' => self::WORDS[$outcome] ?? null,
                ]);
                $this->thread->refresh($lead);

                // The only RingCentral calls anybody is told about (Jose,
                // 2026-10-15: "just report the Missed Calls instead from now on";
                // 2026-10-16: menu hang-ups excluded, voicemails included).
                if (($outcome === self::MISSED || $outcome === self::VOICEMAIL)
                    && $times[$i]->gte(CarbonImmutable::now()->subMinutes(self::ANNOUNCE_WITHIN_MINUTES))) {
                    $this->events->emitAfterCommit('lead.missed_call', $lead, ['after' => [
                        'phone' => $lead->phone,
                        'contact_name' => $lead->contact_name,
                        'line' => $line,
                        'occurred_at' => $times[$i]->toIso8601String(),
                        'source' => $lead->source,
                    ]]);
                }
            });
            $filed++;
        }

        return $filed;
    }

    /**
     * Calls filed before Google's copy of the same call arrived: withdrawn, and
     * a lead left with no conversation goes with them.
     */
    public function withdrawDuplicates(CarbonImmutable $since): int
    {
        $rows = LeadConversation::query()->where('remote_id', 'like', 'rc\_%')
            ->where('occurred_at', '>=', $since)->with('lead')->get();
        if ($rows->isEmpty()) {
            return 0;
        }
        $ls = $this->lsCalls($since, CarbonImmutable::now());

        $withdrawn = 0;
        foreach ($rows as $row) {
            if (! self::matches($ls[self::ten($row->lead?->phone)] ?? [], CarbonImmutable::parse($row->occurred_at))) {
                continue;
            }
            $lead = $row->lead;
            $row->delete();
            if ($lead !== null && $lead->source === Lead::SOURCE_RINGCENTRAL && ! $lead->conversations()->exists()) {
                $lead->delete();
            } elseif ($lead !== null) {
                $this->thread->refresh($lead);
            }
            $withdrawn++;
        }

        return $withdrawn;
    }

    /**
     * The words Reports already has for these calls (TranscribeReportCalls,
     * Whisper or the matching LS call), so a call is never transcribed twice.
     */
    public function fillTranscripts(): int
    {
        $rows = LeadConversation::query()->where('remote_id', 'like', 'rc\_%')
            ->whereNotNull('recording_url')->whereNull('transcript')->limit(500)->get();
        $words = CallReportTranscript::query()
            ->whereIn('call_id', $rows->map(fn ($r) => substr((string) $r->remote_id, 3)))
            ->whereNotNull('transcript')->get()->keyBy('call_id');

        $filled = 0;
        foreach ($rows as $row) {
            $found = $words[substr((string) $row->remote_id, 3)] ?? null;
            if ($found === null) {
                continue;
            }
            $row->forceFill([
                'transcript' => $found->transcript,
                'transcript_labelled' => $found->transcript_labelled,
                'transcribed_at' => now(),
            ])->save();
            $filled++;
        }

        return $filled;
    }

    /**
     * Null when somebody picked up. A call that never left the phone menu (no
     * queue, nobody's phone rang) is a hang-up: robocalls, mostly.
     *
     * @param  array<string, mixed>  $call
     */
    public static function outcome(array $call): ?string
    {
        return match (true) {
            $call['answered_by'] !== null => ($call['queue'] ?? null) === self::EXISTING_CLIENT_QUEUE ? self::EXISTING_CLIENT : null,
            ($call['result'] ?? null) === 'Voicemail' => self::VOICEMAIL,
            $call['queue'] === null && ($call['rang'] ?? []) === [] => self::POSSIBLE_SPAM,
            default => self::MISSED,
        };
    }

    /** @param  array<string, mixed>  $staff  names whose phones rang, as keys */
    public static function isLeadCall(array $call, array $staff): bool
    {
        $queue = $call['queue'] ?? null;

        return ($call['direction'] ?? 'Inbound') === 'Inbound'
            && ($call['id'] ?? null) !== null
            && ($call['started_at'] ?? null) !== null
            && self::ten($call['from'] ?? null) !== null
            && ! ($queue !== null && isset($staff[$queue]));
    }

    /** "NJ - GMB" => "NJ"; "AngiSweep" => "ANGISWEEP". */
    public static function market(string $line): string
    {
        return strtoupper(trim(explode(' - ', $line)[0]));
    }

    /**
     * A brand line's company: the company whose name starts with the line's
     * name, or the other way round ("Crestline" / "Crestline Home"). Null for a
     * market-coded line, which is A-1 Chimney's.
     *
     * @param  array<string, string>  $companies  id => name
     */
    public static function companyFor(string $line, array $companies): ?string
    {
        $key = fn (string $s) => preg_replace('/[^a-z0-9]/', '', strtolower($s)) ?? '';
        $name = $key(preg_replace('/^\d+\s+/', '', trim(explode(' - ', $line)[0])) ?? '');
        if (strlen($name) < 4 || isset(self::MARKETS[self::market($line)])) {
            return null;
        }
        foreach ($companies as $id => $company) {
            $c = $key($company);
            if ($c !== '' && (str_starts_with($c, $name) || str_starts_with($name, $c))) {
                return (string) $id;
            }
        }

        return null;
    }

    private static function ten(mixed $phone): ?string
    {
        $d = preg_replace('/\D/', '', (string) $phone) ?? '';

        return strlen($d) >= 10 ? substr($d, -10) : null;
    }

    /** @param  list<int>  $stamps */
    private static function matches(array $stamps, CarbonImmutable $at): bool
    {
        foreach ($stamps as $t) {
            if (abs($t - $at->getTimestamp()) <= self::MATCH_SECONDS) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, list<int>> Local Services calls by the caller's ten digits */
    private function lsCalls(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $by = [];
        foreach (LeadConversation::query()
            ->join('leads', 'leads.id', '=', 'lead_conversations.lead_id')
            ->where('leads.source', Lead::SOURCE_LOCAL_SERVICES)
            ->where('lead_conversations.channel', LeadConversation::CHANNEL_PHONE_CALL)
            ->where('lead_conversations.participant', LeadConversation::FROM_CONSUMER)
            // Google's calls only: a RingCentral call filed on the caller's LS
            // lead is not one, and counting it matched every such call to
            // itself (0.0.0441's first run withdrew 50 that way).
            ->where('lead_conversations.remote_id', 'not like', 'rc\_%')
            ->whereBetween('lead_conversations.occurred_at', [$from->subHour(), $to->addHour()])
            ->get(['leads.phone_digits', 'lead_conversations.occurred_at']) as $row) {
            if (($d = self::ten($row->phone_digits)) !== null) {
                $by[$d][] = CarbonImmutable::parse($row->occurred_at)->getTimestamp();
            }
        }

        return $by;
    }

    /** The caller's inbox lead from the last 30 days, newest first. */
    private function leadFor(string $digits, CarbonImmutable $at): ?Lead
    {
        return Lead::query()
            ->where('phone_digits', 'like', '%'.$digits)
            ->whereIn('source', Lead::INBOX_SOURCES)
            ->where('status', '!=', Lead::STATUS_JOB_APPLICANT)
            ->where('received_at', '>=', $at->subDays(self::SAME_PERSON_DAYS))
            ->orderByDesc('received_at')
            ->first();
    }
}
