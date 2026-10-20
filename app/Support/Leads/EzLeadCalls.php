<?php

namespace App\Support\Leads;

use App\Models\Franchise;
use App\Models\Lead;
use App\Models\LeadConversation;
use App\Models\PhoneCall;
use App\Models\PhoneMessage;
use App\Models\PhoneNumber;
use App\Models\User;
use App\Support\Automations\EventEmitter;
use App\Support\Phone\NumberNames;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Calls and texts to the business's own EzBizi (Twilio) lines, in Leads as EZ
 * Calls and EZ Texts (Jose, 2026-10-16: "eventually we'll move out of Ring
 * Central so we should also have a tab on leads for calls made via EzBizi too
 * ... as well as EZ Texts").
 *
 * Built like RC Calls (RingCentralLeadCalls), read from Phone's own tables:
 *   - only lines Phone marks `lead_line` (Communication › Settings › Numbers);
 *   - one lead per caller: a caller's inbox lead from the last 30 days collects
 *     the call or text whichever line it came in on, so a number that moves
 *     from RingCentral to EzBizi keeps one lead;
 *   - a call is tagged as RC Calls are - Missed call, Voicemail, Possible spam
 *     (hung up in the menu, PhoneCall::isMenuHangUp), Existing client (the
 *     number is a customer's, NumberNames) - and missed calls and voicemails
 *     raise lead.missed_call;
 *   - a text is somebody writing in: it raises the ordinary lead events and is
 *     answered on the thread (EzTextReplies).
 */
final class EzLeadCalls
{
    public const CALL = 'ez_call_';

    public const TEXT = 'ez_text_';

    private const ANNOUNCE_WITHIN_MINUTES = 60;

    private const WORDS = [
        RingCentralLeadCalls::POSSIBLE_SPAM => 'Possible spam',
        RingCentralLeadCalls::MISSED => 'Missed call',
        RingCentralLeadCalls::VOICEMAIL => 'Voicemail',
    ];

    /** A call still ringing or talking is not filed yet; its tag is not known. */
    private const OVER = [
        PhoneCall::STATUS_COMPLETED, PhoneCall::STATUS_NO_ANSWER, PhoneCall::STATUS_BUSY,
        PhoneCall::STATUS_FAILED, PhoneCall::STATUS_CANCELED, PhoneCall::STATUS_BLOCKED,
    ];

    public function __construct(
        private readonly LeadThread $thread,
        private readonly EventEmitter $events,
        private readonly NumberNames $names,
    ) {}

    /** Calls and texts since $since on this tenant's lead lines. Returns how many were filed. */
    public function file(CarbonImmutable $since): int
    {
        $lines = PhoneNumber::query()->where('lead_line', true)->get()->keyBy('id');
        if ($lines->isEmpty()) {
            return 0;
        }

        // In and out (Jose, 2026-10-16: "all calls should show up"), never one
        // of our own lines calling another.
        $own = PhoneNumber::query()->pluck('e164')->map(fn ($n) => self::ten($n))->filter()->flip();
        $calls = PhoneCall::query()->whereIn('number_id', $lines->keys())
            ->whereIn('direction', [PhoneCall::DIRECTION_INBOUND, PhoneCall::DIRECTION_OUTBOUND])
            ->whereIn('status', self::OVER)
            ->where('created_at', '>=', $since)->orderBy('created_at')->get();
        $texts = PhoneMessage::query()->whereIn('number_id', $lines->keys())
            ->where('direction', PhoneMessage::DIRECTION_INBOUND)
            ->where('created_at', '>=', $since)->orderBy('created_at')->get();

        $have = LeadConversation::query()->whereIn('remote_id', [
            ...$calls->map(fn ($c) => self::CALL.$c->id),
            ...$texts->map(fn ($m) => self::TEXT.$m->id),
        ])->pluck('remote_id')->flip();
        $known = $this->names->for($calls->map(fn ($c) => self::them($c)));
        $people = User::query()->whereIn('id', $calls->pluck('answered_by')->merge($calls->pluck('user_id'))->filter())->pluck('name', 'id');

        $filed = 0;
        foreach ($calls as $call) {
            $them = self::them($call);
            if (! isset($have[self::CALL.$call->id]) && ($d = self::ten($them)) !== null && ! isset($own[$d])) {
                $existing = ($known[$them]['kind'] ?? null) === NumberNames::CUSTOMER;
                $out = $call->direction === PhoneCall::DIRECTION_OUTBOUND;
                if ($this->fileCall($call, $lines[$call->number_id], $existing, $people[$out ? $call->user_id : $call->answered_by] ?? null)) {
                    $filed++;
                }
            }
        }
        foreach ($texts as $text) {
            if (! isset($have[self::TEXT.$text->id]) && self::ten($text->from_e164) !== null) {
                $this->fileText($text, $lines[$text->number_id]);
                $filed++;
            }
        }

        return $filed;
    }

    public static function outcome(PhoneCall $call, bool $existingCustomer): ?string
    {
        return match (true) {
            $call->answered_by !== null => $existingCustomer ? RingCentralLeadCalls::EXISTING_CLIENT : null,
            (bool) $call->voicemail => RingCentralLeadCalls::VOICEMAIL,
            $call->isMenuHangUp() => $existingCustomer ? RingCentralLeadCalls::EXISTING_CLIENT : RingCentralLeadCalls::POSSIBLE_SPAM,
            default => RingCentralLeadCalls::MISSED,
        };
    }

    /** The customer's end: who called in, or whom we called. */
    private static function them(PhoneCall $call): string
    {
        return (string) ($call->direction === PhoneCall::DIRECTION_OUTBOUND ? $call->to_e164 : $call->from_e164);
    }

    private function fileCall(PhoneCall $call, PhoneNumber $line, bool $existing, ?string $answeredBy): bool
    {
        $at = CarbonImmutable::parse($call->created_at);
        if ($call->direction === PhoneCall::DIRECTION_OUTBOUND) {
            return $this->fileOutbound($call, $line, $existing, $answeredBy, $at);
        }
        $outcome = self::outcome($call, $existing);

        DB::transaction(function () use ($call, $line, $at, $outcome, $answeredBy) {
            $lead = $this->leadFor((string) $call->from_e164, $at, $line, Lead::SOURCE_EZ_CALLS, self::CALL.$call->id, $outcome);
            if ($outcome !== RingCentralLeadCalls::POSSIBLE_SPAM) {
                CallerLead::reopen($lead);
            }
            // A caller who got through is no longer a menu hang-up; a later
            // hang-up never relabels a real call (as RC Calls).
            if ($lead->source === Lead::SOURCE_EZ_CALLS && $lead->source_status !== $outcome && $outcome !== RingCentralLeadCalls::POSSIBLE_SPAM) {
                $lead->forceFill(['source_status' => $outcome])->save();
            }

            $answered = $call->answered_by !== null;
            $this->thread->store($lead, self::CALL.$call->id, [
                'channel' => LeadConversation::CHANNEL_PHONE_CALL,
                'participant' => LeadConversation::FROM_CONSUMER,
                'occurred_at' => $at,
                'duration_ms' => $answered && $call->duration_seconds !== null ? (int) $call->duration_seconds * 1000 : null,
                'recording_url' => $call->recording_sid !== null ? 'twilio:'.$call->id : null,
                'line_number' => $line->e164,
                'line_name' => $line->label ?: null,
                'transcript' => $call->transcript ?? $call->voicemail_transcript,
                'text' => $answered ? null : (self::WORDS[$outcome] ?? null),
            ]);
            $this->thread->refresh($lead);

            if ($answeredBy !== null) {
                Lead::query()->whereKey($lead->getKey())->whereNull('dispatcher')->whereNull('dispatcher_by_user_id')
                    ->toBase()->update(['dispatcher' => mb_substr($answeredBy, 0, 120)]);
            }

            if (($outcome === RingCentralLeadCalls::MISSED || $outcome === RingCentralLeadCalls::VOICEMAIL)
                && $at->gte(CarbonImmutable::now()->subMinutes(self::ANNOUNCE_WITHIN_MINUTES))) {
                $this->events->emitAfterCommit('lead.missed_call', $lead, ['after' => [
                    'phone' => $lead->phone,
                    'contact_name' => $lead->contact_name,
                    'line' => (string) ($line->label ?: $line->e164),
                    'occurred_at' => $at->toIso8601String(),
                    'source' => $lead->source,
                ]]);
            }
        });

        return true;
    }

    /**
     * Our call out: on the person's lead, or a lead of its own tagged Outbound
     * when nobody has the number; never for a customer with no lead (their
     * calls belong on the customer). Never an alert, never "somebody wrote in".
     */
    private function fileOutbound(PhoneCall $call, PhoneNumber $line, bool $customer, ?string $by, CarbonImmutable $at): bool
    {
        $them = self::them($call);
        if ($customer && $this->existingLead($them, $at) === null) {
            return false;
        }

        DB::transaction(function () use ($call, $line, $at, $by, $them) {
            $lead = $this->leadFor($them, $at, $line, Lead::SOURCE_EZ_CALLS, self::CALL.$call->id, RingCentralLeadCalls::OUTBOUND);
            $this->thread->store($lead, self::CALL.$call->id, [
                'channel' => LeadConversation::CHANNEL_PHONE_CALL,
                'participant' => LeadConversation::FROM_ADVERTISER,
                'occurred_at' => $at,
                'duration_ms' => $call->status === PhoneCall::STATUS_COMPLETED && $call->duration_seconds ? (int) $call->duration_seconds * 1000 : null,
                'recording_url' => $call->recording_sid !== null ? 'twilio:'.$call->id : null,
                'line_number' => $line->e164,
                'line_name' => $line->label ?: null,
                'transcript' => $call->transcript,
                'text' => $by !== null ? mb_substr($by, 0, 120) : null,
            ]);
            $this->thread->refresh($lead);
        });

        return true;
    }

    private function fileText(PhoneMessage $text, PhoneNumber $line): void
    {
        $at = CarbonImmutable::parse($text->created_at);

        DB::transaction(function () use ($text, $line, $at) {
            $lead = $this->leadFor((string) $text->from_e164, $at, $line, Lead::SOURCE_EZ_TEXTS, self::TEXT.$text->id, null);
            // Somebody wrote in: LeadThread raises the ordinary events and reopens it.
            $this->thread->store($lead, self::TEXT.$text->id, [
                'channel' => LeadConversation::CHANNEL_MESSAGE,
                'participant' => LeadConversation::FROM_CONSUMER,
                'occurred_at' => $at,
                'text' => (string) $text->body,
            ]);
            $this->thread->refresh($lead);
        });
    }

    /** The caller's inbox lead from the last 30 days, or a new one on this line's company and branch. */
    private function existingLead(string $from, CarbonImmutable $at): ?Lead
    {
        return CallerLead::find(self::ten($from), $at);
    }

    private function leadFor(string $from, CarbonImmutable $at, PhoneNumber $line, string $source, string $remoteId, ?string $outcome): Lead
    {
        $found = $this->existingLead($from, $at);
        if ($found !== null) {
            return $found;
        }

        [$companyId, $franchiseId] = match ($line->scope_type) {
            PhoneNumber::SCOPE_FRANCHISE => [Franchise::query()->whereKey($line->scope_id)->value('company_id'), $line->scope_id],
            PhoneNumber::SCOPE_COMPANY => [$line->scope_id, null],
            default => [null, null],
        };

        return Lead::create([
            'organization_id' => $line->organization_id,
            'company_id' => $companyId,
            'franchise_id' => $franchiseId,
            'source' => $source,
            'remote_id' => $remoteId,
            'lead_type' => $source === Lead::SOURCE_EZ_CALLS ? 'PHONE_CALL' : 'MESSAGE',
            'category' => $line->label ? mb_substr((string) $line->label, 0, 191) : null,
            'phone' => Lead::e164($from),
            'phone_digits' => Lead::digits($from),
            'status' => Lead::STATUS_NEW,
            'received_at' => $at,
            'source_status' => $outcome,
        ]);
    }

    private static function ten(?string $phone): ?string
    {
        $digits = preg_replace('/\D/', '', (string) $phone) ?? '';

        return strlen($digits) >= 10 ? substr($digits, -10) : null;
    }
}
