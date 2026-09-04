<?php

namespace App\Http\Controllers\Api;

use App\Models\Customer;
use Illuminate\Support\Collection;
use Illuminate\Database\Eloquent\Model;
use App\Models\Franchise;
use App\Support\Audit\AuditLogger;
use App\Support\Automations\EventEmitter;
use App\Support\Numbering\RecordNumberGenerator;
use App\Support\Settings\SettingsResolver;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * Customers (plan §5.2) — the first half of the vertical slice.
 *
 * Shared org-wide, so the list is deliberately NOT filtered to the caller's
 * franchises. That is the feature, not an oversight: a customer served by two
 * franchises is one person, and hiding them from the second creates two
 * half-histories of one relationship.
 *
 * What keeps that honest is the owning-franchise tag on every response, so the
 * UI can show who is accountable before anybody acts.
 */
class CustomersController extends Controller
{
    public function __construct(
        private readonly RecordNumberGenerator $numbers,
        private readonly SettingsResolver $settings,
        private readonly AuditLogger $audit,
        private readonly TenantContext $tenant,
        private readonly EventEmitter $events,
    ) {}

    /**
     * An `exists` rule scoped to this organization.
     *
     * Laravel's plain `exists` runs a raw query with no tenant scope, so naming
     * another organization's franchise id passes validation and only fails later
     * as a 404 from the Eloquent lookup. Two different answers for two different
     * ids is an existence oracle: it tells a caller which ids are real somewhere
     * else on the platform. Scoping the rule makes both cases identical.
     */
    private function existsInTenant(string $table): \Illuminate\Validation\Rules\Exists
    {
        return Rule::exists($table, 'id')
            ->where('organization_id', $this->tenant->idOrFail());
    }

    /** GET /api/v1/customers */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Customer::class);

        $scope = \App\Support\Scoping\ViewScope::forUser($request->user());

        $customers = Customer::query()
            /*
             * The franchise's COMPANY comes with it, because the name alone
             * does not identify it. Two companies in one organization may each
             * have a franchise called Florida, and a row tagged "Florida" then
             * says nothing about which — see `present()` for why that tag
             * carries the weight it does.
             *
             * `company_id` is in the select because the nested load needs the
             * foreign key, not because anything reads it. Trimming it to "only
             * what we need" does not error: the belongsTo resolves to null and
             * `company` goes quietly empty. That is the bug this line already
             * had once, on the duplicate-check path.
             */
            ->with('owningFranchise:id,name,company_id', 'owningFranchise.company:id,name')
            ->withCount('jobs')
            /*
             * Scoped by OWNING franchise, and only when a search is not running.
             *
             * Owned-by rather than served-by, because a customer belongs to
             * exactly one franchise: the counts across companies then add up to
             * the organization's total, which they would not if everyone who had
             * done work for somebody counted them.
             *
             * Search deliberately escapes the scope. Customers are shared
             * org-wide (§5.2) and the whole point of sharing is that a customer
             * served by two branches is ONE person — somebody who cannot find an
             * existing record creates a duplicate, which is the exact failure
             * sharing exists to prevent. The owning-franchise tag on every row is
             * what keeps the wider result honest.
             */
            ->when(
                ! $scope->isOrganization() && blank($request->query('search')),
                fn ($q) => $q->whereIn('owning_franchise_id', $scope->franchiseIds()),
            )
            // Optional narrowing, NOT an authorization boundary — sharing means
            // everything here is already readable. A filter that looked like a
            // permission would be the more dangerous kind of confusion.
            ->when($request->query('franchise_id'), fn ($q, $id) => $q->where('owning_franchise_id', $id))
            ->when($request->query('search'), function ($q, $term) {
                $q->where(function ($inner) use ($term) {
                    $inner->where('name', 'like', "%{$term}%")
                        ->orWhere('email', 'like', "%{$term}%")
                        ->orWhere('phone', 'like', "%{$term}%")
                        ->orWhere('number', 'like', "%{$term}%");
                });
            })
            ->when($request->boolean('active_only'), fn ($q) => $q->where('is_active', true))
            ->orderBy('name')
            ->paginate(perPage: min((int) $request->query('per_page', 50), 200));

        return response()->json([
            'data' => collect($customers->items())->map(fn (Customer $c) => $this->present($c)),
            'meta' => [
                'total'        => $customers->total(),
                'per_page'     => $customers->perPage(),
                'current_page' => $customers->currentPage(),
                'last_page'    => $customers->lastPage(),
            ],
        ]);
    }

    /** GET /api/v1/customers/{customer} */
    public function show(Customer $customer): JsonResponse
    {
        $this->authorize('view', $customer);

        return response()->json(['data' => $this->present($customer, detailed: true)]);
    }

    /**
     * POST /api/v1/customers
     *
     * Possible duplicates come back in the response rather than blocking it
     * (§5.2 — never auto-merge, and a second household on one phone number is
     * real). The client shows them; a human decides.
     */
    public function store(Request $request): JsonResponse
    {
        $input = $request->validate($this->rules());

        $franchise = Franchise::findOrFail($input['owning_franchise_id']);

        $this->authorize('create', [Customer::class, $franchise]);

        $customer = DB::transaction(function () use ($input, $franchise) {
            $customer = Customer::create($input + [
                'number' => $this->numbers->next(RecordNumberGenerator::CUSTOMERS, $franchise),
            ]);

            // Written inside the transaction, so the record and its history
            // commit together or not at all (§10).
            $this->audit->log('customer.created', $customer, metadata: [
                'owning_franchise' => $franchise->name,
                'number'           => $customer->number,
            ]);

            $this->events->emit('customer.created', $customer, ['after' => $customer->only([
                'name', 'email', 'phone', 'owning_franchise_id', 'is_active',
            ])]);

            return $customer;
        });

        return response()->json([
            'data' => $this->present($customer->fresh(), detailed: true),
            'possible_duplicates' => $this
                ->duplicateCandidates($input, $franchise, ignoreId: $customer->getKey())
                ->map(fn (Customer $c) => $this->present($c)),
        ], Response::HTTP_CREATED);
    }

    /** PATCH /api/v1/customers/{customer} */
    public function update(Request $request, Customer $customer): JsonResponse
    {
        $this->authorize('update', $customer);

        // owning_franchise_id is absent on purpose. Reassignment is a separate,
        // separately authorised act (§5.2) — allowing it here would let a
        // routine edit move accountability as a side effect.
        $input = $request->validate($this->rules(forUpdate: true));

        DB::transaction(function () use ($customer, $input) {
            $customer->fill($input);

            $changed = $customer->getDirty();
            $before  = array_intersect_key($customer->getOriginal(), $changed);

            $this->audit->logChange('customer.updated', $customer);

            $customer->save();

            if ($changed !== []) {
                $this->events->emit('customer.updated', $customer, [
                    'before' => $before, 'after' => $changed,
                ]);
            }
        });

        return response()->json(['data' => $this->present($customer->fresh(), detailed: true)]);
    }

    /**
     * POST /api/v1/customers/{customer}/owner
     *
     * Moving accountability. Deliberate and audited: a customer permanently
     * relocating is real, but it must be an explicit act rather than a side
     * effect of one franchise doing one job (§5.2).
     */
    public function reassignOwner(Request $request, Customer $customer): JsonResponse
    {
        $input = $request->validate([
            'owning_franchise_id' => ['required', 'uuid', $this->existsInTenant('franchises')],
            'reason'              => ['nullable', 'string', 'max:500'],
        ]);

        $newOwner = Franchise::findOrFail($input['owning_franchise_id']);

        $this->authorize('reassignOwner', [$customer, $newOwner]);

        if ($newOwner->is($customer->owningFranchise)) {
            return response()->json([
                'message' => 'That franchise already owns this customer.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $previous = $customer->owningFranchise;

        DB::transaction(function () use ($customer, $newOwner, $previous, $input) {
            $customer->owning_franchise_id = $newOwner->getKey();
            $customer->save();

            // Names as well as ids: the franchise may be renamed or deactivated
            // later, and "who was accountable in March" has to stay answerable.
            $this->audit->log('customer.owner_reassigned', $customer, metadata: [
                'from'   => ['id' => $previous?->getKey(), 'name' => $previous?->name],
                'to'     => ['id' => $newOwner->getKey(), 'name' => $newOwner->name],
                'reason' => $input['reason'] ?? null,
            ]);

            $this->events->emit('customer.owner_reassigned', $customer, [
                'before' => ['owning_franchise_id' => $previous?->getKey()],
                'after'  => ['owning_franchise_id' => $newOwner->getKey()],
            ]);
        });

        return response()->json(['data' => $this->present($customer->fresh(), detailed: true)]);
    }

    /** DELETE /api/v1/customers/{customer} */
    public function destroy(Customer $customer): JsonResponse
    {
        $this->authorize('delete', $customer);

        if ($customer->jobs()->exists()) {
            // Soft delete would keep the row, but a job list pointing at a
            // customer the UI refuses to show reads as data loss.
            return response()->json([
                'message' => 'This customer has jobs. Deactivate them instead — '
                    .'removing them would leave that work with no one attached to it.',
                'code'    => 'has_jobs',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        DB::transaction(function () use ($customer) {
            $this->audit->log('customer.deleted', $customer);
            $this->events->emit('customer.deleted', $customer, [
                'before' => $customer->only(['name', 'owning_franchise_id']),
            ]);
            $customer->delete();
        });

        return response()->json(['message' => 'Customer deleted.']);
    }

    // ── shared ───────────────────────────────────────────────────

    private function rules(bool $forUpdate = false): array
    {
        $required = $forUpdate ? 'sometimes' : 'required';

        return [
            // `missing`, not `prohibited`: prohibited is satisfied by a
            // present-but-null value, which then reached fill() and violated a
            // NOT NULL column as a 500 instead of a refusal.
            'owning_franchise_id' => $forUpdate
                ? ['missing']
                : ['required', 'uuid', $this->existsInTenant('franchises')],
            'name'          => [$required, 'string', 'max:200'],
            'email'         => ['nullable', 'email', 'max:200'],
            'phone'         => ['nullable', 'string', 'max:40'],
            'address_line1' => ['nullable', 'string', 'max:200'],
            'address_line2' => ['nullable', 'string', 'max:200'],
            'city'          => ['nullable', 'string', 'max:100'],
            'state'         => ['nullable', 'string', 'size:2'],
            'postal_code'   => ['nullable', 'string', 'max:12'],
            'notes'         => ['nullable', 'string', 'max:5000'],
            'is_active'     => ['boolean'],
        ];
    }

    private function present(Customer $customer, bool $detailed = false): array
    {
        $base = [
            'id'     => $customer->id,
            'number' => $customer->number,
            'name'   => $customer->name,
            'email'  => $customer->email,
            'phone'  => $customer->phone,
            'is_active' => $customer->is_active,
            // Always present, on every representation. Its whole job is to stop
            // somebody acting on another franchise's customer without realising,
            // so it cannot be something the detail view alone carries (§5.2).
            'owning_franchise' => [
                'id'   => $customer->owning_franchise_id,
                'name' => $customer->owningFranchise?->name,
                /*
                 * Which company that franchise belongs to.
                 *
                 * The tag above is the thing standing between somebody and
                 * acting on another franchise's customer, and a search
                 * deliberately escapes the view scope (see `index`) — so on the
                 * result set where this matters most, the rows can span the
                 * whole organization. A franchise NAME does not identify one
                 * across that: two companies may each have a Florida, and
                 * "Florida" then answers nothing.
                 *
                 * Null when the customer has no owning franchise, which is the
                 * same "Unassigned" case the name is null for.
                 */
                'company' => $customer->owningFranchise?->company?->only(['id', 'name']),
            ],
            'jobs_count' => $customer->jobs_count ?? $customer->jobs()->count(),
        ];

        if (! $detailed) {
            return $base;
        }

        return $base + [
            'address' => [
                'line1'       => $customer->address_line1,
                'line2'       => $customer->address_line2,
                'city'        => $customer->city,
                'state'       => $customer->state,
                'postal_code' => $customer->postal_code,
            ],
            'notes' => $customer->notes,
            // Derived from jobs, never stored (§5.2).
            'served_by_franchise_ids' => $customer->servedByFranchiseIds(),
            'shared_in_practice'      => $customer->isSharedInPractice(),
            'warn_on_cross_franchise_edit' => (bool) $this->settings->get(
                'customers.warn_on_cross_franchise_edit',
                $customer->owningFranchise ?? $customer->organization,
            ),
            'created_at' => $customer->created_at->toIso8601String(),
        ];
    }
    // ── possible duplicates ──────────────────────────────────────
    //
    // Inlined from App\Services\Customers\DuplicateCustomerFinder on 4 Sep. It
    // had this controller as its only caller and no test of its own, and every
    // dependency it took is one this controller already holds — a class whose
    // whole reason to exist was to be injected here, into the one place that
    // could have called it directly.

    /**
     * Customers that look like this one, by whatever the organization has
     * chosen to match on. Never blocks a save; the list rides along in the
     * response so a person can decide.
     */
    private function duplicateCandidates(array $attributes, Model $scope, ?string $ignoreId = null): Collection
    {
        $mode = (string) $this->settings->get('customers.duplicate_match_on', $scope);

        if ($mode === 'off') {
            return collect();
        }

        $query = Customer::query()->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId));
        $phone = $this->duplicatePhone($attributes['phone'] ?? null);
        $email = $this->duplicateEmail($attributes['email'] ?? null);

        $matched = match ($mode) {
            'phone' => $phone === null ? null : $query->whereRaw(
                $this->duplicatePhoneExpression(), [$phone]
            ),
            'email' => $email === null ? null : $query->where('email', $email),
            'phone_or_email' => ($phone === null && $email === null) ? null : $query->where(
                function ($q) use ($phone, $email) {
                    if ($phone !== null) {
                        $q->orWhereRaw($this->duplicatePhoneExpression(), [$phone]);
                    }
                    if ($email !== null) {
                        $q->orWhere('email', $email);
                    }
                }
            ),
            'name_and_address' => blank($attributes['name'] ?? null) ? null : $query
                ->where('name', $attributes['name'])
                ->where('postal_code', $attributes['postal_code'] ?? null),
            default => null,
        };

        if ($matched === null) {
            return collect();
        }

        return $matched
            ->with('owningFranchise:id,name,company_id', 'owningFranchise.company:id,name')
            ->limit(10)
            ->get();
    }

    private function duplicatePhone(?string $phone): ?string
    {
        if (blank($phone)) {
            return null;
        }
        $digits = preg_replace('/\D+/', '', $phone);

        return strlen((string) $digits) >= 7 ? $digits : null;
    }

    private function duplicateEmail(?string $email): ?string
    {
        return blank($email) ? null : strtolower(trim($email));
    }

    private function duplicatePhoneExpression(): string
    {
        return "REGEXP_REPLACE(COALESCE(phone, ''), '[^0-9]', '') = ?";
    }
}
