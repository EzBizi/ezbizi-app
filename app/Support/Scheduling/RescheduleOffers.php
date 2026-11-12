<?php

namespace App\Support\Scheduling;

use App\Models\Company;
use App\Models\Customer;
use App\Models\Franchise;
use App\Models\Job;
use App\Models\JobNote;
use App\Models\Lead;
use App\Models\Organization;
use App\Models\SchedulingRescheduleOffer;
use App\Models\ServiceArea;
use App\Models\User;
use App\Support\Audit\AuditLogger;
use App\Support\Leads\LeadTexts;
use App\Support\Settings\SettingsResolver;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Throwable;

/**
 * "An earlier opening is available" texts (Shoval, 2026-10-26: "last friday
 * we had empty spots, when we are usually overbooked"). For an area with room
 * on a day, the customers booked later in the same area are texted from the
 * company's own line. Each text opens a lead tagged Reschedule (`lead_type`),
 * so the reply lands in Leads and the office moves the job.
 *
 * Who is offered: jobs in the area's pool booked at least `offer_min_days_out`
 * days after the open day and within three weeks of it, with a customer
 * phone, not offered in the last 30 days. Soonest first, at most
 * `offer_max_sends`, and only inside `offer_hours`.
 */
final class RescheduleOffers
{
    public const MESSAGE = 'scheduling.offer_message';

    /** Overbooking's own wording (Jose, 2026-11-09). */
    public const OVER_MESSAGE = 'scheduling.overbooked_offer_message';

    public const UNDERBOOKED = 'underbooked';

    public const OVERBOOKED = 'overbooked';

    public const MIN_DAYS_OUT = 'scheduling.offer_min_days_out';

    public const MAX_SENDS = 'scheduling.offer_max_sends';

    public const HOURS = 'scheduling.offer_hours';

    public const HORIZON = 'scheduling.offer_horizon_days';

    public const NOT_AGAIN = 'scheduling.offer_not_again_days';

    public function __construct(
        private readonly Openings $openings,
        private readonly SettingsResolver $settings,
        private readonly LeadTexts $texts,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * The day's open windows and who would be texted.
     *
     * @return array{open: list<array{start_time: string, end_time: string, remaining: int}>, candidates: list<array<string, mixed>>, max_sends: int, message: string}
     */
    public function preview(ServiceArea $area, CarbonImmutable $date, string $kind = self::UNDERBOOKED): array
    {
        $franchise = Franchise::findOrFail($area->franchise_id);
        // Overbooked: the customers on $date are offered the next day with room.
        $offerDay = $kind === self::OVERBOOKED ? $this->nextWithRoom($area, $date, $franchise) : $date;
        $open = $offerDay === null ? [] : collect($this->openings->days($area, $offerDay, 1)[0]['windows'])
            ->filter(fn ($w) => $w['remaining'] > 0)
            ->map(fn ($w) => ['start_time' => $w['start_time'], 'end_time' => $w['end_time'], 'remaining' => $w['remaining']])
            ->values()->all();
        $max = (int) $this->setting(self::MAX_SENDS, $franchise);
        $candidates = $open === [] ? collect() : ($kind === self::OVERBOOKED
            ? $this->bookedOn($area, $date)
            : $this->candidates($area, $date, $franchise));

        return [
            'open' => $open,
            'offer_date' => $offerDay?->toDateString(),
            'candidates' => $candidates->take($max)->values()->all(),
            'max_sends' => $max,
            'message' => (string) $this->setting($kind === self::OVERBOOKED ? self::OVER_MESSAGE : self::MESSAGE, $franchise),
        ];
    }

    /**
     * Texts the chosen jobs, which must be among preview()'s candidates.
     *
     * @param  list<string>  $jobIds
     * @return array{sent: list<string>, failed: list<array{job_id: string, reason: string}>}
     */
    public function send(ServiceArea $area, CarbonImmutable $date, array $jobIds, User $by, string $kind = self::UNDERBOOKED): array
    {
        $franchise = Franchise::findOrFail($area->franchise_id);
        $this->assertInHours($franchise);
        $plan = $this->preview($area, $date, $kind);
        abort_if($plan['open'] === [], 422, $kind === self::OVERBOOKED
            ? 'No day in the next weeks has a spot to move to.'
            : 'That day has no open spot left to offer.');
        $from = $date;
        $date = CarbonImmutable::parse($plan['offer_date']);

        $chosen = collect($plan['candidates'])->whereIn('job_id', $jobIds);
        $company = Company::find($franchise->company_id);
        $window = $plan['open'][0];
        $sent = [];
        $failed = [];

        foreach ($chosen as $c) {
            $body = strtr($plan['message'], [
                '{name}' => trim(explode(' ', (string) $c['customer_name'])[0]) ?: 'there',
                '{company}' => (string) ($company?->name ?? ''),
                '{date}' => $date->format('D M j'),
                '{booked}' => $from->format('D M j'),
                '{window}' => self::window($window['start_time'], $window['end_time']),
            ]);
            $lead = Lead::create([
                'company_id' => $franchise->company_id,
                'franchise_id' => $franchise->id,
                'job_id' => $c['job_id'],
                'source' => Lead::SOURCE_EZ_TEXTS,
                'remote_id' => 'offer_'.$c['job_id'].'_'.$date->toDateString(),
                'contact_name' => $c['customer_name'],
                'phone' => $c['phone'],
                'phone_digits' => Lead::digits($c['phone']),
                'lead_type' => 'Reschedule',
                'summary' => 'Offered '.$date->format('D M j').' instead of '.CarbonImmutable::parse($c['scheduled_for'])->format('D M j'),
                'status' => Lead::STATUS_NEW,
                'received_at' => now(),
            ]);
            try {
                $this->texts->send($lead, $body, $by);
            } catch (Throwable $e) {
                $lead->delete();
                $failed[] = ['job_id' => $c['job_id'], 'reason' => $e->getMessage()];

                continue;
            }
            SchedulingRescheduleOffer::create([
                'franchise_id' => $franchise->id, 'service_area_id' => $area->id, 'job_id' => $c['job_id'],
                'lead_id' => $lead->id, 'open_date' => $date->toDateString(), 'booked_for' => $c['scheduled_for'], 'sent_by' => $by->id,
                'start_time' => $window['start_time'], 'end_time' => $window['end_time'],
            ]);
            $sent[] = $c['job_id'];
        }

        $this->audit->log('scheduling.reschedule_offers_sent', $area, metadata: [
            'date' => $date->toDateString(), 'sent' => count($sent), 'failed' => count($failed),
        ]);

        return ['sent' => $sent, 'failed' => $failed];
    }

    /**
     * The customer said yes (Jose, 2026-10-28: Charles O'Neil): the job moves
     * to the offered day and window in EzBizi, mirrored to Workiz. One click,
     * not on the reply itself: a "yes" read by a model is not a booking. The
     * window must still have room, or the move is refused by name.
     */
    public function move(SchedulingRescheduleOffer $offer): Job
    {
        abort_if($offer->moved_at !== null, 409, 'This appointment was already moved.');
        abort_if($offer->declined_at !== null, 409, 'The customer replied NO to this move.');
        abort_if($offer->open_date->isBefore(today()), 409, 'That opening has passed. Pick another time on the board.');
        $job = Job::query()->findOrFail($offer->job_id);
        $area = ServiceArea::query()->findOrFail($offer->service_area_id);
        $date = CarbonImmutable::parse($offer->open_date);
        $windows = collect($this->openings->days($area, $date, 1)[0]['windows'] ?? []);
        $window = $offer->start_time === null
            ? $windows->firstWhere(fn ($w) => $w['remaining'] > 0)
            : $windows->first(fn ($w) => $w['start_time'] === substr((string) $offer->start_time, 0, 5));
        abort_if($window === null || $window['remaining'] < 1, 409,
            'That window on '.$date->format('D M j').' is full now. Pick another time on the board.');

        JobMove::to($job, $date->toDateString(), $window['start_time'], $window['end_time'], ['reschedule_offer' => $offer->id]);
        $offer->forceFill(['moved_at' => now(), 'start_time' => $window['start_time'], 'end_time' => $window['end_time']])->save();

        return $job;
    }

    /** The first day after $date, within the horizon, with a spot left. */
    private function nextWithRoom(ServiceArea $area, CarbonImmutable $date, Franchise $franchise): ?CarbonImmutable
    {
        $day = collect($this->openings->days($area, $date->addDay(), (int) $this->setting(self::HORIZON, $franchise)))
            ->first(fn ($d) => collect($d['windows'])->contains(fn ($w) => $w['remaining'] > 0));

        return $day === null ? null : CarbonImmutable::parse($day['date']);
    }

    /**
     * Customers booked on an overbooked day, not offered lately, with a phone.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function bookedOn(ServiceArea $area, CarbonImmutable $date): Collection
    {
        return $this->reachable($area, $this->openings->jobsOn($area, $date)
            ->filter(fn ($j) => $j->kind === 'job' && $j->customer_id !== null));
    }

    /** @return Collection<int, array<string, mixed>> */
    private function candidates(ServiceArea $area, CarbonImmutable $date, Franchise $franchise): Collection
    {
        $from = $date->addDays((int) $this->setting(self::MIN_DAYS_OUT, $franchise));
        $jobs = $this->openings->jobsBetween($area, $from, $date->addDays((int) $this->settings->get(self::HORIZON, $area->franchise) + 1))
            ->filter(fn ($j) => $j->kind === 'job' && $j->customer_id !== null);

        return $this->reachable($area, $jobs);
    }

    /**
     * Jobs not offered in the not-again window, with a textable customer, soonest first.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function reachable(ServiceArea $area, Collection $jobs): Collection
    {
        $offered = SchedulingRescheduleOffer::query()->whereIn('job_id', $jobs->pluck('job_id'))
            ->where('created_at', '>=', now()->subDays((int) $this->settings->get(self::NOT_AGAIN, $area->franchise)))->pluck('job_id')->flip();
        $customers = Customer::query()->whereIn('id', $jobs->pluck('customer_id'))->get(['id', 'name', 'phone'])->keyBy('id');

        return $jobs->reject(fn ($j) => isset($offered[$j->job_id]))
            ->map(fn ($j) => [
                'job_id' => $j->job_id,
                'scheduled_for' => (string) $j->scheduled_for,
                'customer_name' => $customers[$j->customer_id]?->name,
                'phone' => $customers[$j->customer_id]?->phone,
            ])
            ->filter(fn ($c) => Lead::e164($c['phone']) !== null)
            ->sortBy('scheduled_for')->values();
    }

    private function assertInHours(Franchise $franchise): void
    {
        $hours = (array) $this->setting(self::HOURS, $franchise);
        $zone = (string) $this->settings->get('timezone', Organization::find($franchise->organization_id));
        $now = CarbonImmutable::now($zone)->format('H:i');
        abort_if(($hours['start'] ?? null) !== null && ($now < $hours['start'] || $now >= ($hours['end'] ?? '24:00')), 422,
            "Offers go out between {$hours['start']} and {$hours['end']}; it is {$now} now.");
    }

    private function setting(string $key, Franchise $franchise): mixed
    {
        return $this->settings->get($key, $franchise);
    }

    /**
     * "8-11 AM", "11 AM-2 PM", "2:30-5 PM": ASCII only, so the text stays GSM-7
     * (an en dash sends the whole text as UCS-2, 70 characters a part).
     */
    public static function window(string $start, string $end): string
    {
        $t = fn (string $hhmm) => CarbonImmutable::createFromFormat('H:i', substr($hhmm, 0, 5));
        $h = fn (CarbonImmutable $c) => $c->minute === 0 ? $c->format('g') : $c->format('g:i');
        [$s, $e] = [$t($start), $t($end)];

        return $s->format('A') === $e->format('A')
            ? $h($s).'-'.$h($e).' '.$e->format('A')
            : $h($s).' '.$s->format('A').'-'.$h($e).' '.$e->format('A');
    }

    /**
     * A NO to an open offer keeps the customer's time: noted on the job, the
     * offer closed (desk, 2026-11-12, as Project Management's texts do). Not
     * consumed: the reply still lands on the lead's thread.
     */
    public function absorbNo(string $from, string $body): void
    {
        $digits = Lead::digits($from);
        if ($digits === '' || ! preg_match('/^\s*(n|no|nope|nah)\b/iu', $body)) {
            return;
        }
        $offer = SchedulingRescheduleOffer::query()
            ->whereNull('moved_at')->whereNull('declined_at')
            ->where('open_date', '>=', today()->toDateString())
            ->whereIn('lead_id', Lead::query()->where('phone_digits', $digits)->select('id'))
            ->latest()->first();
        if ($offer === null) {
            return;
        }
        $offer->forceFill(['declined_at' => now()])->save();
        $job = Job::query()->find($offer->job_id);
        if ($job !== null) {
            JobNote::query()->create(['organization_id' => $job->organization_id, 'job_id' => $job->id,
                'body' => 'Customer replied NO to moving to '.$offer->open_date->format('D M j').': keeps '.$offer->booked_for->format('D M j').'. Reply: '.trim($body)]);
        }
    }
}
