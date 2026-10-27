<?php

namespace App\Http\Controllers\Api;

use App\Models\Customer;
use App\Models\Job;
use App\Models\Membership;
use App\Models\MembershipPlan;
use App\Models\User;
use App\Support\Access\Refusal;
use App\Support\Audit\AuditLogger;
use App\Support\Automations\EventEmitter;
use App\Support\Scoping\ViewScope;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * Customers › Memberships (Jose, 2026-10-05). Members are rows of
 * `memberships`, one per year; plans are Settings.
 *
 * Amounts follow Jobs' money rule: without `jobs.financials` every amount is
 * null rather than the row being refused, so a dispatcher still sees who is a
 * member and when they are due.
 */
class MembershipsController extends Controller
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly AuditLogger $audit,
        private readonly EventEmitter $events,
    ) {}

    /** The latest year of every member in the current view. */
    public function index(Request $request): JsonResponse
    {
        $this->need($request->user(), 'memberships.members.view', 'see members');

        $rows = Membership::query()
            ->with(['plan', 'customer', 'visitJob'])
            ->whereIn('franchise_id', ViewScope::forUser($request->user())->franchiseIds())
            // One customer's every year (the member page's "Every year"),
            // else the latest year of each member.
            ->when($request->query('customer_id'), fn ($q, $id) => $q->where('customer_id', $id))
            ->orderByDesc('starts_on')
            ->get();

        if (! $request->query('customer_id')) {
            // ponytail: latest year per customer in PHP; a window query when
            // an organization has tens of thousands of members.
            $rows = $rows->unique('customer_id')->values();
        }

        $money = $request->user()->hasPermission('jobs.financials');
        $avgJob = $money ? $this->averageJobs($rows->pluck('customer_id')->all()) : [];

        return response()->json(['data' => $rows->map(fn (Membership $m) => $this->present($m, $money, $avgJob))]);
    }

    public function store(Request $request): JsonResponse
    {
        $input = $request->validate([
            'customer_id'        => ['required', 'uuid', $this->existsInTenant('customers')],
            'membership_plan_id' => ['required', 'uuid', $this->existsInTenant('membership_plans')],
            'starts_on'          => ['nullable', 'date'],
            'paid_minor'         => ['nullable', 'integer', 'min:0'],
        ]);

        $customer = Customer::findOrFail($input['customer_id']);
        $this->need($request->user(), 'memberships.create', 'add a member');
        $this->reach($request->user(), $customer->owning_franchise_id, 'add this member');

        $starts = CarbonImmutable::parse($input['starts_on'] ?? 'today');
        $previous = Membership::query()->where('customer_id', $customer->getKey())->exists();

        $membership = Membership::create([
            'franchise_id'       => $customer->owning_franchise_id,
            'customer_id'        => $customer->getKey(),
            'membership_plan_id' => $input['membership_plan_id'],
            'enrollment'         => $previous ? 'renewal' : 'new',
            'starts_on'          => $starts,
            // Jose: "it sets a timer for 1 year".
            'ends_on'            => $starts->addYear()->subDay(),
            'paid_minor'         => $input['paid_minor'] ?? 0,
        ]);

        $this->audit->log('membership.created', $membership);
        $this->events->emitAfterCommit('membership.created', $membership, ['after' => $this->payload($membership)]);

        return response()->json(['data' => $this->present($membership->fresh(['plan', 'customer', 'visitJob']), true, [])], Response::HTTP_CREATED);
    }

    public function update(Request $request, Membership $membership): JsonResponse
    {
        $input = $request->validate([
            'membership_plan_id' => ['sometimes', 'uuid', $this->existsInTenant('membership_plans')],
            'paid_minor'         => ['sometimes', 'integer', 'min:0'],
            'cancelled'          => ['sometimes', 'boolean'],
        ]);

        $this->need($request->user(), 'memberships.edit', 'change a membership');
        $this->reach($request->user(), $membership->franchise_id, 'change this membership');

        if (array_key_exists('paid_minor', $input) && ! $request->user()->hasPermission('jobs.financials')) {
            abort(Response::HTTP_FORBIDDEN, 'Money is hidden for your role.');
        }

        $membership->fill(collect($input)->except('cancelled')->all());
        if (array_key_exists('cancelled', $input)) {
            $membership->cancelled_at = $input['cancelled'] ? ($membership->cancelled_at ?? now()) : null;
        }
        $this->audit->logChange('membership.updated', $membership);
        $membership->save();

        return response()->json(['data' => $this->present($membership->fresh(['plan', 'customer', 'visitJob']), $request->user()->hasPermission('jobs.financials'), [])]);
    }

    public function destroy(Request $request, Membership $membership): JsonResponse
    {
        $this->need($request->user(), 'memberships.delete', 'delete a membership');
        $this->reach($request->user(), $membership->franchise_id, 'delete this membership');

        $this->audit->log('membership.deleted', $membership);
        $membership->delete();

        return response()->json(null, Response::HTTP_NO_CONTENT);
    }

    // ── Plans (the Settings tab) ─────────────────────────────────

    public function plans(Request $request): JsonResponse
    {
        $this->need($request->user(), 'memberships.view', 'see membership plans');

        return response()->json(['data' => MembershipPlan::query()->orderBy('name')->get()]);
    }

    public function storePlan(Request $request): JsonResponse
    {
        $this->settings($request->user());
        $plan = MembershipPlan::create($this->planInput($request));
        $this->audit->log('membership_plan.created', $plan);

        return response()->json(['data' => $plan], Response::HTTP_CREATED);
    }

    public function updatePlan(Request $request, MembershipPlan $plan): JsonResponse
    {
        $this->settings($request->user());
        $plan->fill($this->planInput($request, $plan));
        $this->audit->logChange('membership_plan.updated', $plan);
        $plan->save();

        return response()->json(['data' => $plan]);
    }

    private function planInput(Request $request, ?MembershipPlan $plan = null): array
    {
        $required = $plan ? 'sometimes' : 'required';

        $input = $request->validate([
            'name'        => [$required, 'string', 'max:120', Rule::unique('membership_plans')
                ->where('organization_id', $this->tenant->idOrFail())->ignore($plan?->getKey())],
            // What a visit includes, one line, shown on the join page.
            'description' => ['sometimes', 'nullable', 'string', 'max:255'],
            'price_minor' => [$required, 'integer', 'min:0'],
            'billing'     => [$required, Rule::in(MembershipPlan::BILLINGS)],
            // The job type a confirmed visit's EzBizi job gets (Jose: per plan;
            // 2026-10-27: native, Workiz mirrors it). workiz_job_type is the
            // old name the plan editor still sends.
            'job_type'        => ['sometimes', 'nullable', 'string', 'max:100'],
            'workiz_job_type' => ['sometimes', 'nullable', 'string', 'max:100'],
            'is_active'   => ['sometimes', 'boolean'],
        ]);

        if (array_key_exists('workiz_job_type', $input)) {
            $input['job_type'] ??= $input['workiz_job_type'];
            unset($input['workiz_job_type']);
        }

        return $input;
    }

    // ── Helpers ──────────────────────────────────────────────────

    private function present(Membership $m, bool $money, array $avgJob): array
    {
        $plan = $m->plan;
        $customer = $m->customer;

        return [
            'id'            => $m->getKey(),
            'customer_id'   => $m->customer_id,
            'customer_name' => $customer?->name,
            // Jose: "show the phone number as well on members, and email if available".
            'customer_phone' => Customer::realPhone($customer?->phone),
            'customer_email' => Customer::realEmail($customer?->email),
            'state'         => $customer?->state,
            'zip'           => $customer?->postal_code,
            'enrollment'    => $m->enrollment,
            'plan_id'       => $plan->getKey(),
            'plan_name'     => $plan->name,
            'billing'       => $plan->billing,
            'starts_on'     => $m->starts_on->toDateString(),
            'ends_on'       => $m->ends_on->toDateString(),
            'status'        => $m->state(),
            'reminded_at'   => $m->reminded_at?->toIso8601String(),
            // The next visit the reminder's link booked, if any: its day (the
            // stored wall-clock date, no zone arithmetic) and its job number.
            'visit_on'         => $m->visitJob ? substr((string) $m->visitJob->getRawOriginal('scheduled_for'), 0, 10) ?: null : null,
            'visit_job_number' => $m->visitJob?->number,
            'currency'      => $plan->currency,
            'price_minor'   => $money ? $plan->price_minor : null,
            'paid_minor'    => $money ? $m->paid_minor : null,
            'due_minor'     => $money ? $m->dueMinor() : null,
            // Jose: "plan + avg job value" — the plan's year plus what one of
            // their jobs usually bills.
            'estimated_gross_minor' => $money ? $plan->price_minor + ($avgJob[$m->customer_id] ?? 0) : null,
        ];
    }

    /** @return array<string, int> customer id => average job amount */
    private function averageJobs(array $customerIds): array
    {
        return Job::query()
            ->whereIn('customer_id', $customerIds)
            ->whereNotNull('amount_minor')
            ->groupBy('customer_id')
            ->selectRaw('customer_id, AVG(amount_minor) as avg_minor')
            ->pluck('avg_minor', 'customer_id')
            ->map(fn ($v) => (int) round((float) $v))
            ->all();
    }

    public static function payload(Membership $m): array
    {
        return [
            'plan_name'  => $m->plan?->name,
            'enrollment' => $m->enrollment,
            'starts_on'  => $m->starts_on->toDateString(),
            'ends_on'    => $m->ends_on->toDateString(),
            // For a message to read naturally: "Hi Marta, ... on October 4, 2027".
            'customer_name' => Customer::query()->whereKey($m->customer_id)->value('name'),
            'ends_on_text'  => $m->ends_on->format('F j, Y'),
        ];
    }

    private function settings(User $user): void
    {
        $this->need($user, 'memberships.settings.view', 'change membership plans');
        $this->need($user, 'memberships.edit', 'change membership plans');
    }

    private function need(User $user, string $key, string $noun): void
    {
        if (! $user->hasPermission($key)) {
            throw new AuthorizationException(Refusal::permission($noun)->message());
        }
    }

    private function reach(User $user, string $franchiseId, string $act): void
    {
        $franchise = \App\Models\Franchise::findOrFail($franchiseId);
        if (! $user->canActOnFranchise($franchise)) {
            throw new AuthorizationException(Refusal::noReach($user, Refusal::AT_FRANCHISE, $act, $franchise->name)->message());
        }
    }

    private function existsInTenant(string $table): \Illuminate\Validation\Rules\Exists
    {
        return Rule::exists($table, 'id')->where('organization_id', $this->tenant->idOrFail());
    }
}
