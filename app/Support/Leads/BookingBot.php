<?php

namespace App\Support\Leads;

use App\Models\Lead;
use App\Models\LeadConversation;
use App\Models\WebBooking;
use App\Support\Ai\GrokCli;
use App\Support\Scheduling\Openings;
use App\Support\Scheduling\SlotOffer;
use App\Support\Settings\SettingsResolver;
use App\Support\Workiz\WorkizJobWriter;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The booking bot (Jose, 2026-11-03: "Build the system as well, for now one
 * tap approvals"). Each time a customer writes, it answers with the next
 * thing a booking needs: the service address, then the service, then a real
 * open window near them (or on the day they asked for, "Friday instead"),
 * and once they agree it holds that window as a pending WebBooking that a
 * dispatcher approves with one tap. Approving books the job (LeadsController::
 * decideBooking). It never books by itself.
 *
 * The model only READS the chat: the address, the service and whether they
 * said yes to the time we offered. Every sentence and every time is ours,
 * so it cannot promise a price or a window the schedule does not have.
 * The offered window waits as an OFFERED WebBooking, which holds no seat.
 */
final class BookingBot
{
    private const SCHEMA = [
        'type' => 'object',
        'properties' => [
            'address' => ['type' => ['string', 'null']],
            'city' => ['type' => ['string', 'null']],
            'state' => ['type' => ['string', 'null']],
            'zip' => ['type' => ['string', 'null']],
            'service' => ['type' => ['string', 'null']],
            'job_type' => ['type' => ['string', 'null']],
            'accepts_offer' => ['type' => 'boolean'],
            'asked_for' => ['type' => ['string', 'null']],
            'wants_booking' => ['type' => 'boolean'],
        ],
        'required' => ['address', 'city', 'state', 'zip', 'service', 'job_type', 'accepts_offer', 'asked_for', 'wants_booking'],
        'additionalProperties' => false,
    ];

    public function __construct(
        private readonly SlotOffer $slots,
        private readonly WorkizJobWriter $workiz,
    ) {}

    /** The reply to send now, or null to say nothing (with why, for the run log). */
    public function next(Lead $lead): array
    {
        $why = $this->silent($lead);
        if ($why !== null) {
            return ['reply' => null, 'why' => $why];
        }

        $offer = WebBooking::query()->where('lead_id', $lead->getKey())->first();
        $types = $lead->franchise_id === null ? [] : ($this->workiz->choices($lead->franchise_id, $lead->postal_code)['job_types'] ?? []);

        try {
            $read = GrokCli::about($lead, fn () => GrokCli::ask($this->brief($lead, $offer, $types), self::SCHEMA));
        } catch (Throwable $e) {
            Log::warning('Grok did not answer for the booking bot.', ['reason' => mb_substr($e->getMessage(), 0, 200)]);

            return ['reply' => null, 'why' => 'The model did not answer; a person will reply.'];
        }

        if (($read['wants_booking'] ?? true) === false) {
            return ['reply' => null, 'why' => 'The customer is not asking to book; left for a person.'];
        }

        $this->remember($lead, $read);
        $jobType = in_array($read['job_type'] ?? null, $types, true) ? $read['job_type'] : null;

        // ponytail: the seat is not re-counted on a yes; the dispatcher's tap is the check.
        if ($offer?->status === WebBooking::OFFERED && ($read['accepts_offer'] ?? false) === true
            && $offer->date->gte(CarbonImmutable::today())) {
            $offer->forceFill(['status' => WebBooking::PENDING, 'job_type' => $jobType ?? $offer->job_type])->save();

            return ['reply' => 'Great, we are holding '.$this->label($offer).' for you'
                .($lead->address ? ' at '.$lead->address : '').'. A dispatcher will confirm shortly.', 'why' => 'Held the window for a dispatcher to approve.'];
        }

        if (blank($lead->address) || blank($lead->postal_code)) {
            return ['reply' => 'Thanks! What is the service address, with the street, city and ZIP code?', 'why' => 'Asked for the address.'];
        }
        if (blank($lead->service)) {
            return ['reply' => 'Thanks! What can we help you with?', 'why' => 'Asked what the job is.'];
        }

        $zone = (string) app(SettingsResolver::class)->get('timezone', $lead->organization);
        $today = CarbonImmutable::now($zone)->startOfDay();
        $asked = RequestedWindow::from(trim(($read['asked_for'] ?? '').' '.$this->lastSaid($lead)), $today);
        $slot = $this->slots->for((string) $lead->postal_code, Openings::tomorrow($zone), $asked);

        if ($slot === null) {
            return ['reply' => 'Thank you! A dispatcher will reach out shortly with a time.', 'why' => 'No open window near this ZIP.'];
        }

        $values = ['service_area_id' => $slot['area_id'], 'date' => $slot['date'], 'start_time' => $slot['start_time'],
            'end_time' => $slot['end_time'], 'status' => WebBooking::OFFERED, 'job_type' => $jobType];
        $offer === null
            ? WebBooking::create(['lead_id' => $lead->getKey()] + $values)
            : $offer->forceFill($values)->save();

        $lead_in = $asked !== null && ! $slot['in_requested_window']
            ? 'We do not have an opening on the day you asked for. The closest is '
            : 'Our next opening near you is ';

        return ['reply' => $lead_in.$slot['label'].'. Does that work for you? If another day suits you better, just tell us which.',
            'why' => 'Offered '.$slot['label'].'.'];
    }

    /** Why the bot stays out of it, or null when it may answer. */
    private function silent(Lead $lead): ?string
    {
        return match (true) {
            in_array($lead->status, Lead::STATUSES_NO_REPLY_OWED, true) => 'This enquiry is closed; no bot reply.',
            $lead->job_id !== null || $lead->status === Lead::STATUS_BOOKED => 'Already booked.',
            WebBooking::query()->where('lead_id', $lead->getKey())->whereIn('status', [...WebBooking::HOLDING, WebBooking::DECLINED])->exists() => 'A window is already held or was declined; a person takes it from here.',
            ! GrokCli::configured() => 'The booking bot needs the AI service, which is off.',
            default => null,
        };
    }

    /** What the customer gave, onto the enquiry: a correction replaces the old value. */
    private function remember(Lead $lead, array $read): void
    {
        $zip = preg_match('/^\d{5}$/', trim((string) ($read['zip'] ?? ''))) === 1 ? trim($read['zip']) : null;
        $state = preg_match('/^[A-Za-z]{2}$/', trim((string) ($read['state'] ?? ''))) === 1 ? strtoupper(trim($read['state'])) : null;
        $set = array_filter([
            'address' => self::clip($read['address'] ?? null, 200),
            'city' => self::clip($read['city'] ?? null, 100),
            'service_state' => $state,
            'postal_code' => $zip,
            'service' => self::clip($read['service'] ?? null, 200),
        ], fn ($v) => $v !== null);

        if ($set !== []) {
            $lead->forceFill($set)->save();
        }
    }

    private static function clip(mixed $value, int $max): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : mb_substr($value, 0, $max);
    }

    private function brief(Lead $lead, ?WebBooking $offer, array $types): string
    {
        $offered = $offer?->status === WebBooking::OFFERED ? $this->label($offer) : null;

        return 'You read a text conversation between a home-services business and a customer, and '
            .'answer with JSON only. Report what the CUSTOMER said, never invent anything.'
            ."\naddress: the street address of the job (house number and street), or null."
            ."\ncity, state (2 letters), zip (5 digits): of the job, or null."
            ."\nservice: what they need done, in a few words, or null."
            .($types === [] ? "\njob_type: null." : "\njob_type: the one of these that fits the service, or null: ".implode(' | ', $types))
            ."\naccepts_offer: ".($offered === null ? 'false.' : "true only if the customer's LAST message agrees to {$offered}.")
            ."\nasked_for: the days or times the customer's last message asks for (\"friday\", \"next week\"), or null."
            ."\nwants_booking: false only if the customer is clearly not asking for a visit (a billing question, "
            .'a complaint, a vendor, cancelling).'
            ."\n\n".$this->thread($lead);
    }

    /** Who said what, oldest first, the last 4,000 characters. */
    private function thread(Lead $lead): string
    {
        return mb_substr(LeadConversation::query()->where('lead_id', $lead->getKey())->orderBy('occurred_at')->get()
            ->filter(fn ($c) => trim((string) ($c->text ?? $c->transcript)) !== '')
            ->map(fn ($c) => ($c->participant === LeadConversation::FROM_CONSUMER ? 'Customer: ' : 'Business: ')
                .trim((string) preg_replace('/\s+/', ' ', (string) ($c->text ?? $c->transcript))))
            ->implode("\n"), -4000);
    }

    private function lastSaid(Lead $lead): string
    {
        return (string) LeadConversation::query()->where('lead_id', $lead->getKey())
            ->where('participant', LeadConversation::FROM_CONSUMER)->reorder()->orderByDesc('occurred_at')->value('text');
    }

    /** "Thu Nov 6, 8-11 AM", as SlotOffer labels it. */
    private function label(WebBooking $booking): string
    {
        $clock = fn (string $t) => str_replace(':00', '', CarbonImmutable::parse($booking->date->toDateString().' '.$t)->format('g:i A'));

        return $booking->date->format('D M j').', '.$clock((string) $booking->start_time).' - '.$clock((string) $booking->end_time);
    }
}
