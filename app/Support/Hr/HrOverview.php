<?php

namespace App\Support\Hr;

use App\Models\Assignment;
use App\Models\EmploymentEvent;
use App\Models\Invitation;
use App\Models\Onboarding;
use App\Models\OnboardingAnswer;
use App\Models\TimeClockDay;
use App\Models\TimeClockShift;
use App\Models\User;
use App\Support\Contracts\FieldDefinition;
use App\Support\Dashboard\DashboardPeriod;
use App\Support\Dashboard\Widgets\Hr\ScopesToPlaces;
use App\Support\Settings\SettingsResolver;
use App\Support\Tenancy\TenantContext;
use App\Support\Training\LapsedPasses;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Everything the redesigned HR Dashboard draws, in one read (Jose approved
 * options 1 and 4 of docs/previews/hr-dashboard-layouts.html, 2026-10-01; the
 * shape is UI/UX's). Scoped to the view the way the HR widgets are, with
 * whose-is-whose rules taken from them: joined is the first `started` day,
 * left is a `left` event, an onboarding's place is its invitation's.
 *
 * ponytail: the lists are whole lists capped at LIST_CAP; page them if an
 * organization outgrows that.
 */
final class HrOverview
{
    use ScopesToPlaces;

    private const LIST_CAP = 200;


    public function for(User $viewer, DashboardPeriod $period): array
    {
        $places = $this->placesInView($viewer);
        $from = $period->startsOn->copy()->timezone($period->timezone)->toDateString();
        $to = $period->endsOn->copy()->timezone($period->timezone)->toDateString();
        $today = now($period->timezone)->toDateString();

        $people = fn () => $this->inView($places);
        $describe = $this->describer($places, $viewer);

        $joined = $people()->joinSub($this->firstStarts(), 'firsts', 'firsts.user_id', '=', 'users.id')
            ->whereBetween('firsts.first_started', [$from, $to])
            ->orderByDesc('firsts.first_started')
            ->select('users.*', 'firsts.first_started')
            ->with(['role:id,name', 'assignments.assignedCompany:id,name', 'assignments.assignedFranchise:id,name'])
            ->limit(self::LIST_CAP + 1)->get();

        $leaving = EmploymentEvent::query()->where('kind', EmploymentEvent::LEFT)
            ->whereIn('user_id', $people()->select('users.id'));
        $left = (clone $leaving)->whereBetween('occurred_on', [$from, $to])
            ->with(['user.role:id,name', 'user.assignments.assignedCompany:id,name', 'user.assignments.assignedFranchise:id,name'])
            ->orderByDesc('occurred_on')->limit(self::LIST_CAP)->get();

        $onboardings = $this->inFlight($places)->with(['user:id,name', 'invitation', 'contractVersion.contract:id,name'])
            ->orderBy('started_at')->limit(self::LIST_CAP)->get();

        $stalled = $this->stalledInvitations($places);
        $certifications = $this->certifications($places, $today);

        return [
            'period' => $period->key,
            'from' => $from,
            'to' => $to,

            'on_the_books' => [
                'total' => $people()->count(),
                'active' => $people()->where('users.is_active', true)->count(),
                'inactive' => $people()->where('users.is_active', false)->count(),
            ],

            'joined' => [
                'count' => $people()->joinSub($this->firstStarts(), 'firsts', 'firsts.user_id', '=', 'users.id')
                    ->whereBetween('firsts.first_started', [$from, $to])->count(),
                'people' => $joined->take(self::LIST_CAP)->map(fn (User $u) => $describe($u) + [
                    'started_on' => Carbon::parse($u->first_started)->toDateString(),
                ])->values()->all(),
            ],

            'left' => [
                'count' => (clone $leaving)->whereBetween('occurred_on', [$from, $to])->count(),
                'since' => (clone $leaving)->min('occurred_on'),
                'people' => $left->filter(fn ($e) => $e->user !== null)->map(fn ($e) => $describe($e->user) + [
                    'left_on' => $e->occurred_on->toDateString(),
                ])->values()->all(),
            ],

            'onboarding' => [
                'count' => $this->inFlight($places)->count(),
                'filling_in' => $this->inFlight($places)->where('status', Onboarding::IN_PROGRESS)->count(),
                'to_sign' => $this->inFlight($places)->where('status', Onboarding::SUBMITTED)->count(),
                'to_approve' => $this->inFlight($places)->where('status', Onboarding::AWAITING_APPROVAL)->count(),
                'items' => $onboardings->map(fn (Onboarding $o) => [
                    'id' => $o->getKey(),
                    'user_id' => $o->user_id,
                    'name' => $o->user?->name ?? $this->invitedName($o->invitation),
                    'contract' => $o->contractVersion?->contract?->name,
                    'state' => match ($o->status) {
                        Onboarding::SUBMITTED => 'to_sign',
                        Onboarding::AWAITING_APPROVAL => 'to_approve',
                        default => 'filling_in',
                    },
                    'since' => $o->started_at?->toIso8601String(),
                ])->all(),
            ],

            'invitations_stalled' => [
                'count' => $stalled->count(),
                'expired' => $stalled->where('state', 'expired')->count(),
                'not_opened' => $stalled->where('state', 'not_opened')->count(),
                'items' => $stalled->take(self::LIST_CAP)->values()->all(),
            ],

            'certifications' => [
                'count' => $certifications->count(),
                'lapsed' => $certifications->where('state', 'lapsed')->count(),
                'expiring' => $certifications->where('state', 'expiring')->count(),
                'items' => $certifications->take(self::LIST_CAP)->values()->all(),
            ],

            'by_month' => $this->byMonth($places, $period->timezone),

            ...$this->placesNow($places, $viewer),

            'right_now' => $this->rightNow($viewer, $places, $today),
        ];
    }

    /** People this view covers: assigned in it, or — from the organization — assigned nowhere. */
    private function inView(array $places): Builder
    {
        return User::query()->where(function (Builder $visible) use ($places) {
            $visible->whereHas('assignments', fn (Builder $a) => $this->constrainToPlaces($a, $places));
            if ($this->countsUnplaced($places)) {
                $visible->orWhereDoesntHave('assignments');
            }
        });
    }

    private function firstStarts(): Builder
    {
        return EmploymentEvent::query()->where('kind', EmploymentEvent::STARTED)
            ->groupBy('user_id')->selectRaw('user_id, min(occurred_on) as first_started');
    }

    /** @return callable(User): array{user_id: string, name: string, role: ?string, place: ?string} */
    private function describer(array $places, User $viewer): callable
    {
        $orgName = $viewer->organization?->name;

        return fn (User $u) => [
            'user_id' => $u->getKey(),
            'name' => $u->name,
            'role' => $u->role?->name,
            'place' => $u->assignments->map(fn (Assignment $a) => $a->assignedFranchise?->name
                ?? $a->assignedCompany?->name ?? $orgName)->filter()->unique()->implode(', ') ?: null,
        ];
    }

    private function inFlight(array $places): Builder
    {
        return Onboarding::query()
            ->whereIn('status', Onboarding::OPEN)
            ->where(function (Builder $visible) use ($places) {
                $visible->whereHas('invitation', fn (Builder $i) => $this->constrainToPlaces($i, $places));
                if ($places['organization'] !== null) {
                    $visible->orWhereNull('invitation_id');
                }
            });
    }

    private function invitedName(?Invitation $invitation): ?string
    {
        if ($invitation === null) {
            return null;
        }
        $name = trim(($invitation->first_name ?? '').' '.($invitation->last_name ?? ''));

        return $name !== '' ? $name : $invitation->email;
    }

    private function stalledInvitations(array $places): Collection
    {
        $quietBefore = now()->subDays((int) app(SettingsResolver::class)->get('hr.alerts.quiet_days', app(TenantContext::class)->get()));

        return $this->constrainToPlaces(
            Invitation::query()->whereNull('accepted_at')->whereNull('revoked_at'),
            $places,
        )
            ->where(fn (Builder $q) => $q->where('expires_at', '<', now())
                ->orWhere(fn (Builder $q) => $q->whereNull('opened_at')->where('sent_at', '<', $quietBefore)))
            ->orderBy('sent_at')
            ->get()
            ->map(fn (Invitation $i) => [
                'id' => $i->getKey(),
                'name' => $this->invitedName($i),
                'state' => $i->expires_at->isPast() ? 'expired' : 'not_opened',
                'sent_at' => $i->sent_at?->toIso8601String(),
            ]);
    }

    /** The expiry questions on contracts (ExpiringCertifications' rule): lapsed, or due within 60 days. */
    private function certifications(array $places, string $today): Collection
    {
        $until = Carbon::parse($today)->addDays((int) app(SettingsResolver::class)->get('hr.alerts.expiring_days', app(TenantContext::class)->get()))->toDateString();
        $rows = collect();

        $onboardings = Onboarding::query()->with(['contractVersion', 'user:id,name'])
            ->where(function (Builder $visible) use ($places) {
                $visible->whereHas('invitation', fn (Builder $i) => $this->constrainToPlaces($i, $places));
                if ($places['organization'] !== null) {
                    $visible->orWhereNull('invitation_id');
                }
            })->get();

        foreach ($onboardings as $onboarding) {
            $expiries = collect($onboarding->contractVersion?->fieldSet()->fields ?? [])
                ->filter(fn ($f) => $f instanceof FieldDefinition && $f->isExpiry)->keyBy('key');
            if ($expiries->isEmpty()) {
                continue;
            }

            $answers = OnboardingAnswer::query()->where('onboarding_id', $onboarding->getKey())
                ->whereIn('field_key', $expiries->keys())->get();

            foreach ($answers as $answer) {
                try {
                    $date = Carbon::parse((string) $answer->value)->toDateString();
                } catch (\Throwable) {
                    continue;
                }
                if ($date > $until || $date < Carbon::parse($today)->subYear()->toDateString()) {
                    continue;
                }
                $rows->push([
                    'user_id' => $onboarding->user_id,
                    'name' => $onboarding->user?->name ?? 'Somebody',
                    'certification' => $expiries[$answer->field_key]->label,
                    'state' => $date < $today ? 'lapsed' : 'expiring',
                    'date' => $date,
                ]);
            }
        }

        return $rows->sortBy('date')->values();
    }

    /** The twelve months ending this one, joined and left in each. */
    private function byMonth(array $places, string $timezone): array
    {
        $end = now($timezone)->startOfMonth();
        $start = $end->copy()->subMonths(11);
        $people = $this->inView($places)->select('users.id');

        $joined = DB::query()->fromSub($this->firstStarts(), 'firsts')
            ->whereIn('user_id', $people)
            ->where('first_started', '>=', $start->toDateString())
            ->pluck('first_started')
            ->countBy(fn ($d) => substr((string) $d, 0, 7));

        $left = EmploymentEvent::query()->where('kind', EmploymentEvent::LEFT)
            ->whereIn('user_id', $this->inView($places)->select('users.id'))
            ->where('occurred_on', '>=', $start->toDateString())
            ->pluck('occurred_on')
            ->countBy(fn ($d) => Carbon::parse($d)->format('Y-m'));

        $months = [];
        for ($m = $start->copy(); $m <= $end; $m->addMonth()) {
            $key = $m->format('Y-m');
            $months[] = ['month' => $key, 'joined' => (int) ($joined[$key] ?? 0), 'left' => (int) ($left[$key] ?? 0)];
        }

        return $months;
    }

    /** Active people by place now, and how many are counted twice (one person, several places). */
    private function placesNow(array $places, User $viewer): array
    {
        $names = $this->placeNames($places, $viewer);
        $counts = $this->constrainToPlaces(
            Assignment::query()->join('users', 'users.id', '=', 'assignments.user_id')->where('users.is_active', true),
            $places,
        )
            ->selectRaw('COALESCE(assigned_franchise_id, assigned_company_id, assigned_organization_id) as place_id,'
                .' COUNT(DISTINCT assignments.user_id) as total')
            ->groupBy('place_id')->pluck('total', 'place_id');

        $rows = [];
        foreach ($names as $id => $name) {
            if (($n = (int) ($counts[$id] ?? 0)) > 0) {
                $rows[] = ['name' => $name, 'count' => $n];
            }
        }

        $unplaced = $this->countsUnplaced($places)
            ? User::query()->where('is_active', true)->whereDoesntHave('assignments')->count()
            : 0;
        if ($unplaced > 0) {
            $rows[] = ['name' => null, 'count' => $unplaced];
        }

        $distinct = $this->inView($places)->where('users.is_active', true)->count();

        return ['places' => $rows, 'overlap' => max(0, array_sum(array_column($rows, 'count')) - $distinct)];
    }

    private function rightNow(User $viewer, array $places, string $today): array
    {
        $active = fn () => $this->inView($places)->where('users.is_active', true)->select('users.id');
        $workHours = $viewer->hasPermission('work_hours.general.view');

        return [
            'on_the_clock' => $workHours ? [
                'count' => TimeClockShift::query()->whereNull('clocked_out_at')
                    ->whereIn('user_id', $active())->distinct()->count('user_id'),
                'of_active' => $this->inView($places)->where('users.is_active', true)->count(),
            ] : null,

            'away_today' => $workHours ? (function () use ($active, $today) {
                $tags = TimeClockDay::query()->whereDate('date', $today)
                    ->whereIn('tag', ['vacation', 'sick'])->whereIn('user_id', $active())
                    ->pluck('tag');

                return ['count' => $tags->count(), 'vacation' => $tags->filter(fn ($t) => $t === 'vacation')->count(),
                    'sick' => $tags->filter(fn ($t) => $t === 'sick')->count()];
            })() : null,

            'due_for_retaking' => $viewer->hasPermission('training.monitor') ? [
                'count' => LapsedPasses::currentlyLapsedQuery()
                    ->whereIn('training_attempts.user_id', $active())
                    ->distinct()->count('training_attempts.user_id'),
            ] : null,
        ];
    }
}
