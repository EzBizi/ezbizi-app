<?php

namespace App\Http\Controllers\Api;

use App\Models\PtoRequest;
use App\Models\PtoUsed;
use App\Models\User;
use App\Support\Access\Refusal;
use App\Support\Audit\AuditLogger;
use App\Support\Automations\EventEmitter;
use App\Support\Hr\PtoPolicy;
use App\Support\Notifications\NotificationDispatcher;
use App\Support\Settings\SettingsResolver;
use App\Support\TimeClock\TimeClock;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Staff ask for their own paid time off; whoever may change used days for
 * them approves or denies it (Jose, 2026-10-26).
 *
 * Asking is `work_hours.pto.request`, every role's by the migration of that
 * day, and only ever for yourself. Deciding is `work_hours.pto.edit` within
 * reach, the same as editing Used (UserPolicy::editPtoUsed). Approving writes
 * the days to pto_used, the store the balance already reads.
 */
class PtoRequestsController extends Controller
{
    public function __construct(
        private readonly PtoPolicy $policy,
        private readonly AuditLogger $audit,
        private readonly EventEmitter $events,
        private readonly NotificationDispatcher $notifications,
    ) {}

    /** Mine, and those I may decide; ?status= and ?search= on the person's name. */
    public function index(Request $request): JsonResponse
    {
        $actor = $request->user();
        $rows = PtoRequest::query()->with(['user', 'decidedBy:id,name'])
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->when(trim((string) $request->query('search')), fn ($q, $term) => $q->whereHas(
                'user', fn ($u) => $u->where('name', 'like', "%{$term}%")))
            ->latest()->limit(500)->get()
            ->filter(fn (PtoRequest $r) => $r->user_id === $actor->getKey()
                || ($r->user !== null && $actor->can('editPtoUsed', $r->user)))
            ->values();

        return response()->json(['data' => $rows->map(fn (PtoRequest $r) => $this->present($r, $actor))]);
    }

    /** Ask for days off: refused when it would go below zero, unless the place allows it. */
    public function store(Request $request): JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor->hasPermission('work_hours.pto.request'), 403,
            Refusal::permission('ask for paid time off')->message());

        $input = $request->validate([
            'days' => ['required', 'array', 'min:1', 'max:366'],
            'days.*.date' => ['required', 'date_format:Y-m-d', 'distinct'],
            'days.*.portion' => ['required', 'numeric', 'in:0.5,1'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);
        $dates = array_column($input['days'], 'date');
        $this->refuseTakenDates($actor, $dates);

        $total = (float) array_sum(array_map(fn ($d) => (float) $d['portion'], $input['days']));
        $left = $this->policy->for($actor)['left'];
        $pending = (float) PtoRequest::query()->where('user_id', $actor->getKey())
            ->where('status', PtoRequest::PENDING)->sum('total');
        if ($left !== null && $left - $pending - $total < 0 && ! $this->mayGoNegative($actor)) {
            throw ValidationException::withMessages(['days' => sprintf(
                'That is %s days and you have %s left%s. Ask for fewer days.',
                rtrim(rtrim(number_format($total, 2), '0'), '.'),
                rtrim(rtrim(number_format(max(0, $left - $pending), 2), '0'), '.'),
                $pending > 0 ? ' after the requests still waiting' : '',
            )]);
        }

        $ptoRequest = DB::transaction(function () use ($actor, $input, $total) {
            $row = PtoRequest::create([
                'organization_id' => $actor->organization_id, 'user_id' => $actor->getKey(),
                'days' => array_map(fn ($d) => ['date' => $d['date'], 'portion' => (float) $d['portion']], $input['days']),
                'total' => $total, 'note' => $input['note'] ?? null, 'status' => PtoRequest::PENDING,
            ]);
            $this->audit->log('pto.requested', $actor, ['id' => $row->getKey(), 'days' => $total]);
            $this->events->emit('user.pto_requested', $actor, $this->payload($row));

            return $row;
        });

        foreach ($this->approversFor($actor) as $approver) {
            $this->notifications->dispatch(
                title: 'Paid time off requested',
                body: sprintf('%s asked for %s day%s off.', $actor->name, $this->days($total), $total == 1 ? '' : 's'),
                subject: $ptoRequest, onlyTo: $approver, source: 'work_hours',
            );
        }

        return response()->json(['data' => $this->present($ptoRequest->fresh(['user', 'decidedBy']), $actor)], 201);
    }

    public function approve(Request $request, PtoRequest $ptoRequest): JsonResponse
    {
        return $this->decide($request, $ptoRequest, PtoRequest::APPROVED);
    }

    public function deny(Request $request, PtoRequest $ptoRequest): JsonResponse
    {
        return $this->decide($request, $ptoRequest, PtoRequest::DENIED);
    }

    /** The person who asked takes it back, while it still waits. */
    public function cancel(Request $request, PtoRequest $ptoRequest): JsonResponse
    {
        abort_unless($ptoRequest->user_id === $request->user()->getKey(), 403,
            'Only the person who asked can take a request back.');
        abort_unless($ptoRequest->status === PtoRequest::PENDING, 422, 'This request has already been decided.');

        $ptoRequest->forceFill(['status' => PtoRequest::CANCELLED, 'decided_at' => now()])->save();
        $this->audit->log('pto.request_cancelled', $ptoRequest->user, ['id' => $ptoRequest->getKey()]);

        return response()->json(['data' => $this->present($ptoRequest->fresh(['user', 'decidedBy']), $request->user())]);
    }

    private function decide(Request $request, PtoRequest $ptoRequest, string $status): JsonResponse
    {
        $actor = $request->user();
        $person = $ptoRequest->user;
        abort_if($person === null, 404);
        $this->authorize('editPtoUsed', $person);
        abort_if($person->is($actor), 403, 'Somebody else decides your own request.');
        abort_unless($ptoRequest->status === PtoRequest::PENDING, 422, 'This request has already been decided.');

        $input = $request->validate(['reason' => [$status === PtoRequest::DENIED ? 'required' : 'nullable', 'string', 'max:1000']]);

        DB::transaction(function () use ($ptoRequest, $person, $actor, $status, $input) {
            if ($status === PtoRequest::APPROVED) {
                $this->refuseTakenDates($person, array_column($ptoRequest->days, 'date'), $ptoRequest->getKey());
                foreach ($ptoRequest->days as $day) {
                    PtoUsed::create([
                        'organization_id' => $person->organization_id, 'user_id' => $person->getKey(),
                        'days' => (float) $day['portion'], 'used_on' => $day['date'],
                        'note' => $ptoRequest->note, 'created_by_user_id' => $actor->getKey(),
                    ]);
                }
            }
            $ptoRequest->forceFill([
                'status' => $status, 'decided_by_user_id' => $actor->getKey(), 'decided_at' => now(),
                'decision_reason' => $input['reason'] ?? null,
            ])->save();
            $this->audit->log("pto.request_{$status}", $person, ['id' => $ptoRequest->getKey()]);
            $this->events->emit($status === PtoRequest::APPROVED ? 'user.pto_approved' : 'user.pto_denied',
                $person, $this->payload($ptoRequest));
        });

        $this->notifications->dispatch(
            title: $status === PtoRequest::APPROVED ? 'Paid time off approved' : 'Paid time off denied',
            body: sprintf('%s %s your request for %s day%s off.%s', $actor->name, $status,
                $this->days((float) $ptoRequest->total), (float) $ptoRequest->total == 1 ? '' : 's',
                ($input['reason'] ?? null) ? ' '.$input['reason'] : ''),
            subject: $ptoRequest, onlyTo: $person, source: 'work_hours',
        );

        return response()->json(['data' => $this->present($ptoRequest->fresh(['user', 'decidedBy']), $actor)]);
    }

    /** A date already recorded as a day off, or in another request still waiting, is refused. */
    private function refuseTakenDates(User $person, array $dates, ?string $except = null): void
    {
        $taken = PtoUsed::query()->where('user_id', $person->getKey())->whereNull('set_to')
            ->whereIn('used_on', $dates)->pluck('used_on')->map->toDateString()->all();
        if ($taken !== []) {
            throw ValidationException::withMessages(['days' => 'Already recorded as a day off: '.implode(', ', $taken).'.']);
        }

        // And not asked for twice: a date in another request still waiting.
        $waiting = PtoRequest::query()->where('user_id', $person->getKey())->where('status', PtoRequest::PENDING)
            ->when($except !== null, fn ($q) => $q->whereKeyNot($except))->pluck('days')
            ->flatMap(fn ($days) => array_column($days, 'date'))->intersect($dates)->unique()->values()->all();
        if ($waiting !== []) {
            throw ValidationException::withMessages(['days' => 'Already in a request still waiting: '.implode(', ', $waiting).'.']);
        }
    }

    /** `pto.allow_negative` at the person's own place (organization, company or branch). */
    private function mayGoNegative(User $person): bool
    {
        return (bool) app(SettingsResolver::class)->get('pto.allow_negative', app(TimeClock::class)->placeOf($person));
    }

    /** @return list<User> */
    private function approversFor(User $person): array
    {
        return User::query()->where('is_active', true)->whereKeyNot($person->getKey())->get()
            ->filter(fn (User $u) => $u->can('editPtoUsed', $person))->values()->all();
    }

    private function payload(PtoRequest $row): array
    {
        $person = $row->user;
        $balance = $person === null ? [] : $this->policy->for($person);

        return [
            'person' => ['id' => $person?->getKey(), 'name' => $person?->name],
            'request' => ['id' => $row->getKey(), 'days' => $row->days, 'total' => (float) $row->total,
                'note' => $row->note, 'status' => $row->status, 'reason' => $row->decision_reason],
            'total' => $balance['total'] ?? null, 'used' => $balance['used'] ?? null, 'left' => $balance['left'] ?? null,
        ];
    }

    private function present(PtoRequest $row, User $actor): array
    {
        $person = $row->user;
        $balance = $person === null ? null : $this->policy->for($person);

        return [
            'id' => $row->getKey(),
            'person' => ['id' => $person?->getKey(), 'name' => $person?->name],
            'days' => $row->days,
            'total' => (float) $row->total,
            'note' => $row->note,
            'status' => $row->status,
            'decided_by' => $row->decidedBy?->name,
            'decided_at' => $row->decided_at?->toIso8601String(),
            'reason' => $row->decision_reason,
            'created_at' => $row->created_at?->toIso8601String(),
            // What they have, and what this would leave if approved.
            'left' => $balance['left'] ?? null,
            'would_leave' => $balance === null || $balance['left'] === null || $row->status !== PtoRequest::PENDING
                ? null : round($balance['left'] - (float) $row->total, 2),
            'mine' => $row->user_id === $actor->getKey(),
            'can_decide' => $person !== null && $row->status === PtoRequest::PENDING && $actor->can('editPtoUsed', $person),
        ];
    }

    private function days(float $n): string
    {
        return rtrim(rtrim(number_format($n, 2), '0'), '.');
    }
}
