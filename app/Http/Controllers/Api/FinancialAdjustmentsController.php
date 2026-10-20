<?php

namespace App\Http\Controllers\Api;

use App\Models\FinancialAdjustment;
use App\Models\Franchise;
use App\Models\User;
use App\Support\Audit\AuditLogger;
use App\Support\Scoping\PlaceInView;
use App\Support\Scoping\ViewScope;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Refunds, disputes, returned checks and repair-split moves recorded in
 * EzBizi (financial_adjustments). The repair split and refunds figures add
 * these to the Airtable copy. Allowlisted as WhatsApp commands (AppAct), so a
 * staff member can record one in chat after a YES; the rules are the same
 * here either way.
 */
class FinancialAdjustmentsController extends Controller
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly TenantContext $tenant,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->may($request->user(), 'view');
        $input = $request->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
            'kind' => ['nullable', Rule::in(FinancialAdjustment::KINDS)],
        ]);
        $scope = ViewScope::forUser($request->user());

        $rows = FinancialAdjustment::query()->with('franchise:id,name')
            ->whereBetween('date', [$input['from'], $input['to']])
            ->when($input['kind'] ?? null, fn ($q, $k) => $q->where('kind', $k))
            ->orderBy('date')->limit(1000)->get()
            ->filter(fn (FinancialAdjustment $a) => $scope->coversFranchise($a->franchise_id));

        return response()->json(['data' => $rows->map(fn ($a) => $this->shape($a))->values()]);
    }

    /**
     * Record one. `amount` is dollars (what a person types or says); stored
     * as cents. A repair_move names `from_technician` and `team`.
     */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->may($user, 'edit');
        $input = $request->validate([
            'franchise_id' => ['required_without:place', 'nullable', 'uuid', Rule::exists('franchises', 'id')->where('organization_id', $this->tenant->idOrFail())],
            // A place by name, for chat: one of the places in the current view.
            'place' => ['required_without:franchise_id', 'nullable', 'string', 'max:120'],
            'kind' => ['required', Rule::in(FinancialAdjustment::KINDS)],
            'date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'technician' => ['required', 'string', 'max:120'],
            'from_technician' => ['required_if:kind,repair_move', 'nullable', 'string', 'max:120', 'different:technician'],
            'team' => ['required_if:kind,repair_move', 'nullable', 'string', 'max:120'],
            'amount' => ['required', 'numeric', 'gt:0', 'max:1000000', 'decimal:0,2'],
            'job_number' => ['nullable', 'string', 'max:64'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);
        $franchise = isset($input['franchise_id']) ? Franchise::findOrFail($input['franchise_id']) : $this->placeNamed($user, $input['place']);
        PlaceInView::assert($user, $franchise->company, $franchise, 'adjustment');

        $adjustment = FinancialAdjustment::create([
            'company_id' => $franchise->company_id,
            'franchise_id' => $franchise->id,
            'kind' => $input['kind'],
            'date' => $input['date'],
            'technician' => trim($input['technician']),
            'from_technician' => isset($input['from_technician']) ? trim($input['from_technician']) : null,
            'team' => isset($input['team']) ? trim($input['team']) : null,
            'cents' => (int) round($input['amount'] * 100),
            'job_number' => $input['job_number'] ?? null,
            'reason' => $input['reason'] ?? null,
            'created_by_user_id' => $user->id,
        ]);
        $this->audit->log('financials.adjustment_created', $adjustment, metadata: $adjustment->only('kind', 'date', 'technician', 'from_technician', 'team', 'cents'), actor: $user);

        return response()->json(['data' => $this->shape($adjustment->load('franchise'))], 201);
    }

    /** Correct the amount, reason or job number of one already recorded. */
    public function update(Request $request, FinancialAdjustment $adjustment): JsonResponse
    {
        $user = $request->user();
        $this->may($user, 'edit');
        $this->inView($user, $adjustment);
        $input = $request->validate([
            'amount' => ['sometimes', 'numeric', 'gt:0', 'max:1000000', 'decimal:0,2'],
            'job_number' => ['sometimes', 'nullable', 'string', 'max:64'],
            'reason' => ['sometimes', 'nullable', 'string', 'max:500'],
        ]);
        if (isset($input['amount'])) {
            $input['cents'] = (int) round($input['amount'] * 100);
            unset($input['amount']);
        }
        $adjustment->fill($input)->save();
        $this->audit->logChange('financials.adjustment_updated', $adjustment, $user);

        return response()->json(['data' => $this->shape($adjustment->load('franchise'))]);
    }

    public function destroy(Request $request, FinancialAdjustment $adjustment): JsonResponse
    {
        $user = $request->user();
        $this->may($user, 'delete');
        $this->inView($user, $adjustment);
        $this->audit->log('financials.adjustment_deleted', $adjustment, metadata: $adjustment->only('kind', 'date', 'technician', 'cents'), actor: $user);
        $adjustment->delete();

        return response()->json(null, 204);
    }

    private function placeNamed(User $user, string $name): Franchise
    {
        $scope = ViewScope::forUser($user);
        $matches = Franchise::query()->where('name', trim($name))->get()->filter(fn (Franchise $f) => $scope->coversFranchise($f->id));
        if ($matches->count() !== 1) {
            throw ValidationException::withMessages(['place' => $matches->isEmpty()
                ? "No place called \"{$name}\" in your current view."
                : "More than one place is called \"{$name}\". Switch to the company first."]);
        }

        return $matches->first();
    }

    private function inView(User $user, FinancialAdjustment $adjustment): void
    {
        if (! ViewScope::forUser($user)->coversFranchise($adjustment->franchise_id)) {
            throw ValidationException::withMessages(['adjustment' => 'That record is at a place outside your current view.']);
        }
    }

    private function may(User $user, string $action): void
    {
        abort_unless($user->hasPermission("financials.{$action}"), Response::HTTP_FORBIDDEN,
            $action === 'view' ? 'Your role cannot see Financials.' : 'Your role cannot change refunds or repair splits.');
    }

    private function shape(FinancialAdjustment $a): array
    {
        return [
            'id' => $a->id,
            'franchise_id' => $a->franchise_id,
            'place' => $a->franchise?->name,
            'kind' => $a->kind,
            'date' => $a->date?->toDateString(),
            'technician' => $a->technician,
            'from_technician' => $a->from_technician,
            'team' => $a->team,
            'cents' => $a->cents,
            'job_number' => $a->job_number,
            'reason' => $a->reason,
        ];
    }
}
