<?php

namespace App\Support\Leads;

use App\Models\CallReportTranscript;
use App\Models\CallTypeCorrection;
use App\Models\Lead;
use App\Models\LeadConversation;
use App\Models\Organization;
use App\Models\User;
use App\Support\Ai\GrokCli;
use App\Support\Audit\AuditLogger;
use App\Support\Settings\SettingsResolver;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * New or existing client, per inbound call, from evidence and not from the key
 * pressed (Jose, 2026-10-16: "Sometimes a client on the IVR Menu may press 1
 * or 2 ... and those calls get basically wrongly tagged, so from the
 * transcript as well as the existance in database we should be able to
 * automatically correct all of that").
 *
 * In order, the first that speaks decides:
 *   1. a job of any customer with the caller's last ten digits, any branch
 *      (one person, one customer), booked or scheduled before the call:
 *      existing. Measured on 2026-10-16's 551 calls, Grok read the transcript
 *      the same way on 27 of the 29 such calls that pressed New;
 *   2. the transcript (Grok): "you came last year", "first time calling";
 *   3. otherwise no row - the pressed queue stands.
 * What the caller pressed is stored beside it and never overwritten. Which
 * queue means what is the business's own (Settings: leads.new_client_queue,
 * leads.existing_client_queue). A person's override (decided_by) is final.
 *
 * Leads uses it for the RC Calls tag; Reports for new-client conversion and
 * Scoring (forCalls).
 */
final class CallTypeCorrections
{
    public function __construct(
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Reports' reader: call id => the row, for the calls that have one.
     *
     * @param  list<string>  $callIds
     * @return array<string, array{pressed_queue: ?string, corrected_type: string, reason: string, evidence: string, confidence: int, decided_by: ?string, decided_at: string}>
     */
    public static function forCalls(array $callIds): array
    {
        return CallTypeCorrection::query()->whereIn('call_id', $callIds)->get()
            ->mapWithKeys(fn (CallTypeCorrection $c) => [$c->call_id => [
                'pressed_queue' => $c->pressed_queue,
                'corrected_type' => $c->corrected_type,
                'reason' => $c->reason,
                'evidence' => $c->evidence,
                'confidence' => $c->confidence,
                'decided_by' => $c->decided_by,
                'decided_at' => $c->decided_at->toIso8601String(),
            ]])->all();
    }

    /**
     * Judge the calls not judged yet, in this tenant (RingCentralCallLog rows).
     * Returns how many were decided.
     *
     * @param  list<array<string, mixed>>  $calls
     */
    public function decide(array $calls): int
    {
        $calls = array_values(array_filter($calls, fn ($c) => ($c['direction'] ?? 'Inbound') === 'Inbound'
            && ($c['id'] ?? null) !== null && self::ten($c['from'] ?? null) !== null));
        $have = CallTypeCorrection::query()->whereIn('call_id', array_column($calls, 'id'))->pluck('call_id')->flip();
        $calls = array_values(array_filter($calls, fn ($c) => ! isset($have[$c['id']])));
        if ($calls === []) {
            return 0;
        }

        $org = Organization::query()->findOrFail(app(TenantContext::class)->idOrFail());
        $settings = app(SettingsResolver::class);
        $newQueue = (string) $settings->get('leads.new_client_queue', $org) ?: null;
        $existingQueue = (string) $settings->get('leads.existing_client_queue', $org) ?: null;
        $priorJobs = $this->priorJobs($calls);
        $words = CallReportTranscript::query()->whereIn('call_id', array_column($calls, 'id'))
            ->whereNotNull('transcript')->pluck('transcript', 'call_id');

        $decided = [];
        $ask = [];
        foreach ($calls as $call) {
            $id = (string) $call['id'];
            $pressed = $call['queue'] ?? null;
            $pressedType = match (true) {
                $pressed !== null && $pressed === $existingQueue => CallTypeCorrection::EXISTING,
                $pressed !== null && $pressed === $newQueue => CallTypeCorrection::NEW,
                default => null,
            };
            if (($jobs = $priorJobs[$id] ?? 0) > 0) {
                $decided[$id] = [$pressed, CallTypeCorrection::EXISTING,
                    $jobs === 1 ? 'A job for this caller was booked before the call.' : "{$jobs} jobs for this caller were booked before the call.",
                    'job_match', $pressedType === CallTypeCorrection::NEW ? 90 : 95];

                continue;
            }
            // No job: the words decide, once, when there are any. Otherwise no
            // row - the pressed queue stands - and a transcript arriving later
            // is read on a later run.
            $text = trim((string) ($words[$id] ?? ''));
            if ($text !== '' && GrokCli::configured() && ! Cache::has('call-type-read:'.$id)) {
                $ask[$id] = [$pressed, $text];
            }
        }

        if ($ask !== []) {
            $answers = GrokCli::askMany(array_map(fn ($a) => self::prompt($a[1]), array_values($ask)), self::SCHEMA);
            foreach (array_keys($ask) as $n => $id) {
                $a = $answers[$n];
                if (! $a instanceof RuntimeException) {
                    Cache::put('call-type-read:'.$id, true, now()->addDays(14));   // unclear is an answer too
                }
                if ($a instanceof RuntimeException || ! in_array($a['caller'] ?? null, [CallTypeCorrection::NEW, CallTypeCorrection::EXISTING], true)) {
                    continue;
                }
                $cue = trim(mb_substr((string) ($a['cue'] ?? ''), 0, 200));
                $decided[$id] = [$ask[$id][0], $a['caller'], $cue !== '' ? '"'.$cue.'"' : 'Read from the call.', 'transcript', 75];
            }
        }

        foreach ($decided as $id => [$pressed, $type, $reason, $evidence, $confidence]) {
            CallTypeCorrection::query()->create([
                'organization_id' => $org->getKey(), 'call_id' => $id, 'pressed_queue' => $pressed,
                'corrected_type' => $type, 'reason' => $reason, 'evidence' => $evidence,
                'confidence' => $confidence, 'decided_at' => now(),
            ]);
            $this->retag($id, $type);
        }

        return count($decided);
    }

    /** A person's word on one call; final, and audited. */
    public function override(string $callId, string $type, User $by): CallTypeCorrection
    {
        $row = CallTypeCorrection::query()->firstOrNew(['call_id' => $callId], [
            'organization_id' => app(TenantContext::class)->idOrFail(), 'pressed_queue' => null,
        ]);
        $before = $row->exists ? $row->corrected_type : null;
        $row->fill(['corrected_type' => $type, 'reason' => 'Set by '.$by->name.'.', 'evidence' => 'person',
            'confidence' => 100, 'decided_by' => $by->getKey(), 'decided_at' => now()])->save();
        $this->retag($callId, $type, $before, $by);

        return $row;
    }

    /**
     * The RC Calls tag follows the corrected type on an answered call: Existing
     * client, or not. A missed call, voicemail or hang-up keeps its own tag.
     */
    private function retag(string $callId, string $type, ?string $before = null, ?User $by = null): void
    {
        $row = LeadConversation::query()->where('remote_id', 'rc_'.$callId)->first();
        $lead = $row?->lead;
        if ($lead === null || $lead->source !== Lead::SOURCE_RINGCENTRAL || $row->duration_ms === null) {
            return;
        }
        $tag = $type === CallTypeCorrection::EXISTING ? RingCentralLeadCalls::EXISTING_CLIENT : null;
        if (! in_array($lead->source_status, [null, RingCentralLeadCalls::EXISTING_CLIENT], true) || $lead->source_status === $tag) {
            return;
        }
        $was = $lead->source_status;
        $lead->forceFill(['source_status' => $tag])->save();
        $this->audit->log('lead.caller_type_corrected', $lead,
            ['source_status' => ['from' => $was, 'to' => $tag]],
            ['call_id' => $callId, 'before' => $before, 'corrected_type' => $type], actor: $by);
    }

    /**
     * Jobs before each call, of any customer row with the caller's last ten
     * digits - booked (created) or scheduled before it.
     *
     * @param  list<array<string, mixed>>  $calls
     * @return array<string, int> call id => jobs
     */
    private function priorJobs(array $calls): array
    {
        $digits = array_values(array_unique(array_map(fn ($c) => self::ten($c['from']), $calls)));
        $customers = DB::table('customers')->where('organization_id', app(TenantContext::class)->idOrFail())
            ->whereNull('deleted_at')->whereNotNull('phone')
            ->whereIn(DB::raw("RIGHT(REGEXP_REPLACE(phone, '[^0-9]', ''), 10)"), $digits)
            ->get(['id', DB::raw("RIGHT(REGEXP_REPLACE(phone, '[^0-9]', ''), 10) AS d")]);
        if ($customers->isEmpty()) {
            return [];
        }
        $jobs = DB::table('jobs')->whereIn('customer_id', $customers->pluck('id'))->whereNull('deleted_at')
            ->get(['customer_id', 'created_at', 'scheduled_for']);
        $byDigits = $customers->groupBy('d')->map(fn ($g) => $jobs->whereIn('customer_id', $g->pluck('id')->all()));

        $out = [];
        foreach ($calls as $c) {
            $at = CarbonImmutable::parse((string) $c['started_at']);
            $out[(string) $c['id']] = ($byDigits[self::ten($c['from'])] ?? collect())
                ->filter(fn ($j) => ($j->created_at !== null && CarbonImmutable::parse($j->created_at)->lt($at))
                    || ($j->scheduled_for !== null && CarbonImmutable::parse($j->scheduled_for)->lt($at->startOfDay())))
                ->count();
        }

        return $out;
    }

    private const SCHEMA = ['type' => 'object', 'properties' => [
        'caller' => ['type' => 'string', 'enum' => ['new', 'existing', 'unclear']],
        'cue' => ['type' => 'string'],
    ], 'required' => ['caller', 'cue']];

    private static function prompt(string $transcript): string
    {
        return 'A phone call to a home-services company. From the transcript only, is the CALLER a NEW client '
            .'(first time with this company) or an EXISTING client (has had service before, or is calling about '
            .'an existing job, appointment, invoice or technician)? Answer unclear when the transcript does not '
            ."say. Give the shortest quote that decides it.\n\nTranscript:\n".mb_substr($transcript, 0, 6000);
    }

    private static function ten(?string $phone): ?string
    {
        $d = preg_replace('/\D/', '', (string) $phone) ?? '';

        return strlen($d) >= 10 ? substr($d, -10) : null;
    }
}
