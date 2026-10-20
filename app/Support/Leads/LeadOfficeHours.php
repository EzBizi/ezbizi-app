<?php

namespace App\Support\Leads;

use App\Models\Lead;
use App\Support\Settings\SettingsResolver;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * When the office answering a lead is open, from `leads.office_hours` at the
 * lead's branch (franchise, then company, then organization) in that scope's
 * timezone.
 *
 * Exists for the closed-office auto replies (Jose, 2026-10-05): the first
 * reply promised "a dispatcher will follow up shortly" at 11 PM on a Saturday,
 * and a customer who wrote again overnight heard nothing at all.
 */
class LeadOfficeHours
{
    private const DAYS = ['sunday', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday'];

    public function __construct(private readonly SettingsResolver $settings) {}

    public function isOpen(Lead $lead, ?\DateTimeInterface $now = null): bool
    {
        $local = $this->local($this->scope($lead), $now);
        $hours = $this->hoursOn($this->scope($lead), $local);

        return $hours !== null
            && $local->format('H:i') >= $hours['open']
            && $local->format('H:i') < $hours['close'];
    }

    /**
     * "today at 8:00 AM", "tomorrow at 8:00 AM", "on Monday at 8:00 AM" -
     * the words after "a dispatcher will get back to you". Null when the
     * office has no open day at all.
     */
    public function nextOpening(Lead $lead, ?\DateTimeInterface $now = null): ?string
    {
        return $this->nextOpeningAt($this->scope($lead), $now);
    }

    /** nextOpening() for any place: franchise, company or organization. */
    public function nextOpeningAt(Model $place, ?\DateTimeInterface $now = null): ?string
    {
        $local = $this->local($place, $now);
        $format = $this->settings->get('time_format', $place) === '24h' ? 'H:i' : 'g:i A';

        for ($d = 0; $d <= 7; $d++) {
            $day = $local->addDays($d);
            $hours = $this->hoursOn($place, $day);

            if ($hours === null || ($d === 0 && $local->format('H:i') >= $hours['open'])) {
                continue;
            }

            $at = $day->setTimeFromTimeString($hours['open'])->format($format);

            return match ($d) {
                0 => "today at {$at}",
                1 => "tomorrow at {$at}",
                default => 'on '.$day->format('l')." at {$at}",
            };
        }

        return null;
    }

    /**
     * When the office last closed, so "once per closed period" has a start.
     * A week back when nothing in the last week was open.
     */
    public function closedSince(Lead $lead, ?\DateTimeInterface $now = null): CarbonImmutable
    {
        $local = $this->local($this->scope($lead), $now);

        for ($d = 0; $d <= 7; $d++) {
            $day = $local->subDays($d);
            $hours = $this->hoursOn($this->scope($lead), $day);

            if ($hours === null) {
                continue;
            }

            $close = $day->setTimeFromTimeString($hours['close']);

            if ($close->lte($local)) {
                return $close->utc();
            }
        }

        return $local->subDays(7)->utc();
    }

    /** @return array{open: string, close: string}|null */
    private function hoursOn(Model $place, CarbonImmutable $day): ?array
    {
        $week = (array) $this->settings->get('leads.office_hours', $place);
        $hours = $week[self::DAYS[$day->dayOfWeek]] ?? null;

        return is_array($hours) && isset($hours['open'], $hours['close']) ? $hours : null;
    }

    private function local(Model $place, ?\DateTimeInterface $now): CarbonImmutable
    {
        $zone = (string) ($this->settings->get('timezone', $place) ?: 'UTC');

        return CarbonImmutable::instance($now ?? now())->setTimezone($zone);
    }

    /** Open now at this place (franchise, company or organization) - the email auto reply's office_open (Jose, 2026-10-20). */
    public function isOpenAt(Model $place, ?\DateTimeInterface $now = null): bool
    {
        $local = $this->local($place, $now);
        $hours = $this->hoursOn($place, $local);

        return $hours !== null && $local->format('H:i') >= $hours['open'] && $local->format('H:i') < $hours['close'];
    }

    private function scope(Lead $lead): Model
    {
        return $lead->franchise ?? $lead->company ?? $lead->organization;
    }
}
