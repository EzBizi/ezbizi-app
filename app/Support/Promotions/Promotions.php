<?php

namespace App\Support\Promotions;

use App\Models\Company;
use App\Models\Customer;
use App\Models\Franchise;
use App\Models\Job;
use App\Models\Lead;
use App\Models\LeadConversation;
use App\Models\PhoneMessage;
use App\Models\PhoneNumber;
use App\Models\Promotion;
use App\Models\PromotionText;
use App\Support\Automations\EventEmitter;
use App\Support\Leads\LeadTexts;
use App\Support\Leads\LeadThread;
use App\Support\Phone\PhoneTexts;
use App\Support\Phone\SmsConsent;
use App\Support\Phone\TextRefused;
use App\Support\Phone\TwilioGateway;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Throwable;

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
        private readonly TwilioGateway $twilio,
    ) {}

    /**
     * Who would be texted: the customer of each Done (earned) job scheduled in
     * the range, one row per phone, the latest job's customer first. Each row
     * carries what the screen filters on, so the filters run there, live.
     * ponytail: the range is compared in UTC, a few hours off at the edges.
     *
     * @param  list<string>  $franchiseIds
     * @return Collection<int, array<string, mixed>>
     */
    public function recipients(array $franchiseIds, CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        $jobs = Job::query()->whereIn('franchise_id', $franchiseIds)
            ->whereNotNull('earned_at')->whereNotNull('customer_id')
            ->whereBetween('scheduled_for', [$from->startOfDay()->toDateTimeString(), $to->endOfDay()->toDateTimeString()])
            ->orderByDesc('scheduled_for')
            ->with(['lines' => fn ($q) => $q->whereNotNull('service_id')->select(['id', 'job_id', 'service_id'])])
            ->get(['id', 'franchise_id', 'customer_id', 'scheduled_for', 'job_type', 'tags', 'amount_minor']);
        $customerIds = $jobs->pluck('customer_id')->unique()->values();
        $customers = Customer::query()->whereIn('id', $customerIds)->get(['id', 'name', 'phone', 'tags'])->keyBy('id');
        $stopped = DB::table('sms_opt_outs')->where('organization_id', app(TenantContext::class)->idOrFail())
            ->pluck('e164')->flip();
        $franchises = Franchise::query()->whereIn('id', $franchiseIds)->get(['id', 'name', 'company_id'])->keyBy('id');
        $companies = Company::query()->whereIn('id', $franchises->pluck('company_id'))->pluck('name', 'id');

        // Across every branch, not just the range: "no job since" and "has an
        // open job" ask about the customer, not the campaign.
        $live = fn () => DB::table('jobs')->whereIn('customer_id', $customerIds)->whereRaw("LOWER(status) NOT LIKE 'cancel%'");
        $lastJob = $live()->groupBy('customer_id')->selectRaw('customer_id, MAX(scheduled_for) AS last')->pluck('last', 'customer_id');
        $open = $live()->whereNull('earned_at')->whereRaw("LOWER(status) NOT LIKE 'done%'")->distinct()->pluck('customer_id')->flip();
        $members = DB::table('memberships')->whereIn('customer_id', $customerIds)->whereNull('cancelled_at')
            ->where('ends_on', '>=', now()->toDateString())->distinct()->pluck('customer_id')->flip();

        $rows = [];
        foreach ($jobs as $j) {
            $c = $customers[$j->customer_id] ?? null;
            $e164 = Lead::e164(Customer::realPhone($c?->phone));
            if ($e164 === null) {
                continue;
            }
            $franchise = $franchises[$j->franchise_id] ?? null;
            $rows[$e164] ??= [
                'e164' => $e164,
                'customer_id' => $j->customer_id,
                'name' => $c?->name,
                'first_name' => self::firstName($c?->name),
                'franchise_id' => $j->franchise_id,
                'franchise' => $franchise?->name,
                'company' => (string) ($companies[$franchise?->company_id] ?? ''),
                'job_id' => $j->id,
                'job_date' => $j->scheduled_for?->toDateString(),
                'customer_tags' => $c?->tags ?? [],
                'last_job' => isset($lastJob[$j->customer_id]) ? substr((string) $lastJob[$j->customer_id], 0, 10) : null,
                'open_job' => isset($open[$j->customer_id]),
                'member' => isset($members[$j->customer_id]),
                'opted_out' => isset($stopped[$e164]),
                'jobs' => [],
            ];
            $rows[$e164]['jobs'][] = [
                'date' => $j->scheduled_for?->toDateString(),
                'type' => $j->job_type,
                'tags' => $j->tags ?? [],
                'services' => $j->lines->pluck('service_id')->unique()->values()->all(),
                'amount' => $j->amount_minor === null ? null : $j->amount_minor / 100,
            ];
        }

        return collect(array_values($rows));
    }

    /**
     * Sends up to $batch of a promotion's texts, skipping the phones it has
     * already recorded. True when the list is finished. Only the phones the
     * sender kept ticked, and only while they still qualify.
     */
    public function send(Promotion $promotion, int $batch = PHP_INT_MAX): bool
    {
        $picked = $promotion->phones === null ? null : array_flip($promotion->phones);
        $list = $this->recipients($promotion->franchise_ids, CarbonImmutable::parse($promotion->jobs_from), CarbonImmutable::parse($promotion->jobs_to))
            ->reject(fn ($r) => $r['opted_out'] || ($picked !== null && ! isset($picked[$r['e164']])));
        $done = $promotion->texts()->pluck('e164')->flip();
        $media = self::mediaUrls($promotion->media_path);
        $promotion->update(['recipients' => $list->count()]);

        $left = $list->reject(fn ($r) => isset($done[$r['e164']]));
        foreach ($left->take($batch) as $r) {
            $row = ['promotion_id' => $promotion->id, 'franchise_id' => $r['franchise_id'], 'customer_id' => $r['customer_id'],
                'job_id' => $r['job_id'], 'e164' => $r['e164'], 'contact_name' => $r['name']];

            try {
                $sent = $this->sendOne($r['franchise_id'], $r['e164'], self::fill($promotion->body, $r), $media);
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
     * One text from the line a branch sends from, with the opt-out line. The
     * test send goes through here too, so a test is what a customer gets.
     *
     * @param  list<string>  $mediaUrls
     *
     * @throws TextRefused
     */
    public function sendOne(string $franchiseId, string $to, string $body, array $mediaUrls = []): PhoneMessage
    {
        $line = $this->sender($franchiseId) ?? throw new TextRefused('No texting line is cleared to send for this branch.', 422);
        if ($mediaUrls !== [] && ! $this->takesPictures($line)) {
            throw new TextRefused('This line cannot send pictures.', 422);
        }

        return $this->texts->send($line, $to, $body, automated: true, mediaUrls: $mediaUrls);
    }

    public function sender(string $franchiseId): ?PhoneNumber
    {
        $franchise = Franchise::query()->find($franchiseId, ['id', 'company_id']);

        return $this->lines->line(new Lead(['franchise_id' => $franchiseId, 'company_id' => $franchise?->company_id]));
    }

    /**
     * Whether Twilio lets this line send MMS. Asked once and kept on the
     * number, as Phone > Numbers does; unknown counts as no.
     */
    public function takesPictures(PhoneNumber $line): bool
    {
        if (! isset($line->capabilities['mms']) && $line->connection !== null && filled($line->twilio_sid)) {
            try {
                $caps = $this->twilio->fetchIncoming($line->connection, (string) $line->twilio_sid, $line->account_sid)['capabilities'];
                $line->forceFill(['capabilities' => $caps])->save();
            } catch (Throwable) {
                return false;
            }
        }

        return (bool) ($line->capabilities['mms'] ?? false);
    }

    /** @param  array<string, mixed>  $r  a recipient row: first_name and company */
    public static function fill(string $body, array $r): string
    {
        return strtr($body, ['{first_name}' => (string) ($r['first_name'] ?? 'there'), '{company}' => (string) ($r['company'] ?? '')]);
    }

    /** @return list<string> */
    public static function mediaUrls(?string $path): array
    {
        // A week: Twilio fetches the picture as each text goes, and a long
        // list sends over many minutes.
        return $path === null ? [] : [URL::temporarySignedRoute('promotions.media', now()->addDays(7), ['path' => $path])];
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
            // A named promotion's answers get a Leads tab of that name (LeadsController::whereKind).
            'lead_type' => Promotion::query()->whereKey($text->promotion_id)->value('name') ?: 'Promotion',
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

    public static function firstName(?string $name): string
    {
        $first = trim(explode(' ', trim((string) $name))[0]);

        return $first === '' || ! preg_match('/^\p{L}[\p{L}\'-]*$/u', $first) ? 'there' : ucfirst(mb_strtolower($first));
    }
}
