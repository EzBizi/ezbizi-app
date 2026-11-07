<?php

namespace App\Support\Promotions;

use App\Models\Company;
use App\Models\Customer;
use App\Models\Franchise;
use App\Models\Job;
use App\Models\Lead;
use App\Models\LeadConversation;
use App\Models\Promotion;
use App\Models\PromotionText;
use App\Support\Automations\EventEmitter;
use App\Support\Leads\LeadTexts;
use App\Support\Leads\LeadThread;
use App\Support\Phone\PhoneTexts;
use App\Support\Phone\SmsConsent;
use App\Support\Phone\TextRefused;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Promotions (Jose, 2026-11-06): one text to every customer of the Done jobs
 * in a date range, from Memberships > Promotions. One text per phone, never
 * to a number that replied STOP, always with the opt-out line. A reply opens
 * a Promotions lead carrying the text it answers, so it lands in Leads.
 */
final class Promotions
{
    /** How long after the text a first reply still opens a lead, as LeadTexts. */
    private const REPLY_DAYS = 30;

    public const PLACEHOLDERS = ['{first_name}', '{company}'];

    public function __construct(
        private readonly PhoneTexts $texts,
        private readonly LeadTexts $lines,
        private readonly LeadThread $thread,
        private readonly EventEmitter $events,
    ) {}

    /**
     * Who would be texted: the customer of each Done (earned) job scheduled in
     * the range, one row per phone, the latest job's customer first.
     * ponytail: the range is compared in UTC, a few hours off at the edges.
     *
     * @param  list<string>  $franchiseIds
     * @return Collection<int, array{e164: string, customer_id: string, name: ?string, franchise_id: string, job_id: string, job_date: ?string, opted_out: bool}>
     */
    public function recipients(array $franchiseIds, CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        $jobs = Job::query()->whereIn('franchise_id', $franchiseIds)
            ->whereNotNull('earned_at')->whereNotNull('customer_id')
            ->whereBetween('scheduled_for', [$from->startOfDay()->toDateTimeString(), $to->endOfDay()->toDateTimeString()])
            ->orderByDesc('scheduled_for')
            ->get(['id', 'franchise_id', 'customer_id', 'scheduled_for']);
        $customers = Customer::query()->whereIn('id', $jobs->pluck('customer_id')->unique())->get(['id', 'name', 'phone'])->keyBy('id');
        $stopped = DB::table('sms_opt_outs')->where('organization_id', app(TenantContext::class)->idOrFail())
            ->pluck('e164')->flip();

        return $jobs->map(function (Job $j) use ($customers, $stopped) {
            $c = $customers[$j->customer_id] ?? null;
            $e164 = Lead::e164(Customer::realPhone($c?->phone));

            return $e164 === null ? null : [
                'e164' => $e164,
                'customer_id' => $j->customer_id,
                'name' => $c?->name,
                'franchise_id' => $j->franchise_id,
                'job_id' => $j->id,
                'job_date' => $j->scheduled_for?->toDateString(),
                'opted_out' => isset($stopped[$e164]),
            ];
        })->filter()->unique('e164')->values();
    }

    /**
     * Sends up to $batch of a promotion's texts, skipping the phones it has
     * already recorded. True when the list is finished.
     */
    public function send(Promotion $promotion, int $batch = PHP_INT_MAX): bool
    {
        $list = $this->recipients($promotion->franchise_ids, CarbonImmutable::parse($promotion->jobs_from), CarbonImmutable::parse($promotion->jobs_to))
            ->reject(fn ($r) => $r['opted_out']);
        $done = $promotion->texts()->pluck('e164')->flip();
        $franchises = Franchise::query()->whereIn('id', $promotion->franchise_ids)->get(['id', 'company_id'])->keyBy('id');
        $companies = Company::query()->whereIn('id', $franchises->pluck('company_id'))->pluck('name', 'id');
        $promotion->update(['recipients' => $list->count()]);

        $left = $list->reject(fn ($r) => isset($done[$r['e164']]));
        foreach ($left->take($batch) as $r) {
            $companyId = $franchises[$r['franchise_id']]?->company_id;
            $body = strtr($promotion->body, [
                '{first_name}' => self::firstName($r['name']),
                '{company}' => (string) ($companies[$companyId] ?? ''),
            ]);
            $row = ['promotion_id' => $promotion->id, 'franchise_id' => $r['franchise_id'], 'customer_id' => $r['customer_id'],
                'job_id' => $r['job_id'], 'e164' => $r['e164'], 'contact_name' => $r['name']];
            $line = $this->lines->line(new Lead(['franchise_id' => $r['franchise_id'], 'company_id' => $companyId]));

            try {
                $line ?? throw new TextRefused('No texting line is cleared to send for this branch.', 422);
                $sent = $this->texts->send($line, $r['e164'], $body, automated: true);
                PromotionText::create($row + ['twilio_sid' => $sent->twilio_sid, 'body' => $sent->body, 'sent_at' => now()]);
                $promotion->increment('sent');
            } catch (TextRefused $e) {
                PromotionText::create($row + ['error' => mb_substr($e->getMessage(), 0, 250)]);
                $promotion->increment('failed');
            }
        }

        if ($left->count() > $batch) {
            return false;
        }
        $promotion->update(['finished_at' => now()]);

        return true;
    }

    /**
     * A first answer to a promotion text: a Promotions lead with the text and
     * the answer on its thread. Later answers find the lead through
     * LeadTexts::absorbInbound, which runs first. STOP opens nothing.
     */
    public function absorbReply(string $fromE164, string $body, string $sid): bool
    {
        if (in_array(strtoupper(trim($body, " \t\n\r.!")), SmsConsent::STOP, true)) {
            return false;
        }

        $text = PromotionText::query()->where('e164', $fromE164)->whereNull('lead_id')->whereNotNull('sent_at')
            ->where('sent_at', '>=', now()->subDays(self::REPLY_DAYS))->latest('sent_at')->first();
        if ($text === null) {
            return false;
        }

        $franchise = $text->franchise_id === null ? null : Franchise::query()->find($text->franchise_id);
        $lead = Lead::create([
            'company_id' => $franchise?->company_id,
            'franchise_id' => $text->franchise_id,
            'job_id' => $text->job_id,
            'source' => Lead::SOURCE_PROMOTIONS,
            'remote_id' => 'promo_'.$text->id,
            'contact_name' => $text->contact_name,
            'phone' => $fromE164,
            'phone_digits' => Lead::digits($fromE164),
            'lead_type' => 'Promotion',
            'status' => Lead::STATUS_NEW,
            'received_at' => now(),
        ]);
        // `sms_<sid>` on both rows: EzTextReplies answers from the line the
        // promotion went out on, and LeadTexts files the next answer here.
        $this->thread->store($lead, 'sms_'.$text->twilio_sid, [
            'channel' => LeadTexts::CHANNEL, 'participant' => LeadConversation::FROM_ADVERTISER,
            'occurred_at' => $text->sent_at, 'text' => $text->body, 'is_automatic' => true,
        ]);
        $this->thread->store($lead, 'sms_'.$sid, [
            'channel' => LeadTexts::CHANNEL, 'participant' => LeadConversation::FROM_CONSUMER,
            'occurred_at' => now(), 'text' => $body,
        ]);
        $this->thread->refresh($lead);
        $text->update(['lead_id' => $lead->id]);

        $this->events->emitAfterCommit('lead.received', $lead, [
            'after' => $lead->only(['source', 'contact_name', 'phone', 'category', 'received_at']) + ['kind' => $lead->kind()],
        ]);

        return true;
    }

    private static function firstName(?string $name): string
    {
        $first = trim(explode(' ', trim((string) $name))[0]);

        return $first === '' || ! preg_match('/^\p{L}[\p{L}\'-]*$/u', $first) ? 'there' : ucfirst(mb_strtolower($first));
    }
}
