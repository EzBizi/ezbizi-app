<?php

namespace App\Support\Scheduling;

use App\Models\ServiceArea;
use Carbon\CarbonImmutable;

/**
 * ONE open window to offer a customer (A1 Leads takeover, Jose 2026-10-16
 * "go ahead"): the second auto-reply proposes a concrete slot, as A1 Leads'
 * pipeline/automations/availability.js did from Workiz. Here it comes from
 * the same Openings and NearbySuggestion the Availability tab uses, so an
 * offer can never be a full window and prefers a day beside booked work.
 *
 * Inside $window (the dates the customer asked for, parsed by the caller):
 * the suggested window if it falls inside, else the earliest open one inside;
 * without a window, or when nothing inside is open, the suggested window, else
 * the earliest. Proposes only; booking stays with a person.
 */
final class SlotOffer
{
    public function __construct(private readonly NearbySuggestion $nearby) {}

    /**
     * @param  ?array{start: string, end: string}  $window  Y-m-d, inclusive
     * @return ?array{area_id: string, date: string, start_time: string, end_time: string, label: string, beside_booked_work: bool, in_requested_window: bool}
     */
    public function for(string $zip, CarbonImmutable $from, ?array $window = null): ?array
    {
        $zip = substr(str_pad(preg_replace('/\D+/', '', $zip), 5, '0', STR_PAD_LEFT), 0, 5);
        $area = ServiceArea::forZip($zip);
        if ($area === null || ! Openings::hasCapacity($area)) {
            return null;
        }

        $s = $this->nearby->for($area, $zip, $from);
        $inside = fn (?array $c) => $c !== null && $window !== null && $c['date'] >= $window['start'] && $c['date'] <= $window['end'];

        $pick = match (true) {
            $inside($s['suggested']) => $s['suggested'],
            $window !== null && ($first = $this->firstOpenIn($area, $from, $window)) !== null => $first,
            default => $s['suggested'] ?? $s['earliest'],
        };
        if ($pick === null) {
            return null;
        }

        $at = CarbonImmutable::parse($pick['date']);
        $clock = fn (string $t) => str_replace(':00', '', CarbonImmutable::parse($pick['date'].' '.$t)->format('g:i A'));
        [$a, $b] = [$clock($pick['window']['start_time']), $clock($pick['window']['end_time'])];
        // "7–11 AM", or "11 AM–2 PM" across noon.
        $span = substr($a, -2) === substr($b, -2) ? substr($a, 0, -3).'–'.$b : $a.'–'.$b;

        return [
            'area_id' => $area->id,
            'date' => $pick['date'],
            'start_time' => $pick['window']['start_time'],
            'end_time' => $pick['window']['end_time'],
            'label' => $at->format('D M j').', '.$span,
            'beside_booked_work' => ($pick['nearby'] ?? 0) > 0,
            'in_requested_window' => $inside($pick),
        ];
    }

    /** The earliest open window inside the asked-for dates, or null. */
    private function firstOpenIn(\App\Models\ServiceArea $area, CarbonImmutable $from, array $window): ?array
    {
        $start = max($from->toDateString(), $window['start']);
        $days = (int) CarbonImmutable::parse($start)->diffInDays(CarbonImmutable::parse($window['end'])) + 1;
        if ($days < 1) {
            return null;
        }
        foreach (app(Openings::class)->days($area, CarbonImmutable::parse($start), min($days, 31)) as $day) {
            foreach ($day['windows'] as $w) {
                if ($w['remaining'] > 0) {
                    return ['date' => $day['date'], 'window' => $w, 'nearby' => 0];
                }
            }
        }

        return null;
    }
}
