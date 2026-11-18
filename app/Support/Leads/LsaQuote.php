<?php

namespace App\Support\Leads;

use App\Http\Controllers\Api\Web\EnquiriesController;
use App\Models\Customer;
use App\Models\Lead;
use App\Models\LeadConversation;
use App\Models\Service;
use App\Models\ServiceArea;
use App\Support\Scheduling\Openings;
use App\Support\Settings\SettingsResolver;
use Carbon\CarbonImmutable;

/**
 * The words a first reply can quote (Shoval via Jose, 2026-11-16: "Have the
 * system already give pricing and aviliability and ask for the missing
 * information"): {{price_list}}, {{open_windows}}, {{missing_info}}.
 *
 * Nothing here is composed by a model, so the guard is the source itself: a
 * price is a Services catalogue row's own price_minor, a window is one the
 * booking widget would offer for the lead's ZIP. Each is '' when there is
 * nothing true to say, and ActionRunner drops the line holding it.
 */
final class LsaQuote
{
    public const SETTING = 'leads.quote_services';

    /**
     * "Chimney Sweep - Level 1: $99" per line: catalogue services the request
     * names in full first, then the company's chosen ones (leads.quote_services).
     * Only names that exist in the catalogue with a price are ever quoted.
     */
    public static function prices(Lead $lead): string
    {
        $companyId = $lead->company_id ?? $lead->franchise?->company_id;
        if ($companyId === null) {
            return '';
        }
        $catalogue = Service::query()->where('company_id', $companyId)->whereNull('franchise_id')
            ->where('price_minor', '>', 0)->get(['name', 'price_minor'])
            ->keyBy(fn (Service $s) => mb_strtolower(trim($s->name)));

        $said = mb_strtolower(self::said($lead));
        // Two words at least: a catalogue row called "Service" is in every request.
        $named = $catalogue->filter(fn (Service $s, string $name) => str_word_count($name) >= 2 && str_contains($said, $name))->keys();
        $chosen = collect(explode(',', (string) app(SettingsResolver::class)->get(self::SETTING, $lead->franchise ?? $lead->company ?? $lead->organization)))
            ->map(fn ($n) => mb_strtolower(trim($n)))->filter();

        return $named->merge($chosen)->unique()
            ->filter(fn (string $name) => $catalogue->has($name))
            ->map(fn (string $name) => $catalogue[$name]->name.': $'.self::money($catalogue[$name]->price_minor))
            ->implode("\n");
    }

    /** The next open booking windows for the lead's ZIP, "Mon Nov 23, 8–11 AM", up to $count. */
    public static function windows(Lead $lead, int $count = 3): string
    {
        $zip = preg_match('/\b(\d{5})\b/', (string) ($lead->postal_code ?: self::customer($lead)?->postal_code), $m) ? $m[1] : null;
        $area = $zip === null ? null : ServiceArea::forZip($zip);
        if ($area === null || ! Openings::hasCapacity($area)) {
            return '';
        }

        $out = [];
        foreach (app(EnquiriesController::class)->bookingOpen($area, $lead->organization) as $day) {
            foreach ($day['windows'] as $w) {
                $out[] = CarbonImmutable::parse($day['date'])->format('D M j').', '.self::span($w['start_time'], $w['end_time']);
                if (count($out) === $count) {
                    return implode("\n", $out);
                }
            }
        }

        return implode("\n", $out);
    }

    /** "Could you send your name and the service address with ZIP?" - only what neither the lead nor the customer has. */
    public static function missing(Lead $lead): string
    {
        $customer = self::customer($lead);
        $said = self::said($lead);
        $ask = [];
        if (Lead::firstNameOf($lead->contact_name) === null && Lead::firstNameOf($customer?->name) === null) {
            $ask[] = 'your name';
        }
        $hasAddress = filled($lead->address) && preg_match('/\b\d{5}\b/', $lead->address.' '.$lead->postal_code) === 1;
        if (! $hasAddress && blank($customer?->address_line1)) {
            $ask[] = filled($lead->postal_code) ? 'the street address' : 'the service address with ZIP';
        }
        if (blank($lead->service) && blank($lead->summary) && trim($said) === '') {
            $ask[] = 'what you need done';
        }
        $chimney = $lead->service === 'fireplace_chimney' || preg_match('/chimney|fireplace|stove|flue/i', $said) === 1;
        if ($chimney && preg_match('/\b(wood|gas|electric|pellet|insert|prefab|masonry|brick|zero.?clearance)\b/i', $said) !== 1) {
            $ask[] = 'whether it is a wood, gas or pellet fireplace or stove';
        }
        if ($chimney) {
            $ask[] = 'a photo of the chimney or fireplace if you have one';
        }

        return match (count($ask)) {
            0 => '',
            1 => 'Could you send '.$ask[0].'?',
            default => 'Could you send '.implode(', ', array_slice($ask, 0, -1)).' and '.end($ask).'?',
        };
    }

    /** What the customer wrote, with Google's summary of it. */
    private static function said(Lead $lead): string
    {
        $first = $lead->exists ? (string) $lead->conversations()->reorder()->where('participant', LeadConversation::FROM_CONSUMER)
            ->orderBy('occurred_at')->value('text') : '';

        return trim($first.' '.$lead->summary);
    }

    private static function customer(Lead $lead): ?Customer
    {
        $digits = substr((string) preg_replace('/\D/', '', (string) $lead->phone), -10);
        if (strlen($digits) !== 10) {
            return null;
        }
        // ponytail: the four ways phones are stored; a phone_digits column if a fifth turns up.
        [$a, $b, $c] = [substr($digits, 0, 3), substr($digits, 3, 3), substr($digits, 6)];

        return Customer::query()->whereIn('phone', ['+1'.$digits, $digits, "($a) $b-$c", "$a-$b-$c"])->first();
    }

    private static function money(int $minor): string
    {
        return $minor % 100 === 0 ? number_format($minor / 100) : number_format($minor / 100, 2);
    }

    /** "8–11 AM", or "11 AM–2 PM" across noon, as SlotOffer writes it. */
    private static function span(string $start, string $end): string
    {
        $clock = fn (string $t) => str_replace(':00', '', CarbonImmutable::parse('2000-01-01 '.$t)->format('g:i A'));
        [$a, $b] = [$clock($start), $clock($end)];

        return substr($a, -2) === substr($b, -2) ? substr($a, 0, -3).'–'.$b : $a.'–'.$b;
    }
}
