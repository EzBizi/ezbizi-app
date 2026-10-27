<?php

namespace App\Http\Controllers\Api;

use App\Models\Company;
use App\Models\FinancialResult;
use App\Models\Franchise;
use App\Models\User;
use App\Support\Audit\AuditLogger;
use App\Support\Automations\EventEmitter;
use App\Support\Financials\ResultReader;
use App\Support\Scoping\PlaceInView;
use App\Support\Scoping\ViewScope;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * Financials → Results (Jose, 2026-10-27, option A): an outside lead source's
 * results per date and area, typed in or read from a photo. A row counts in
 * reports only once approved; somebody with financial_results.approve who types one
 * in approves it by saving it. WhatsApp intake (WhatsAppResults) adds pending
 * rows through save().
 */
class FinancialResultsController extends Controller
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly EventEmitter $events,
        private readonly FinancialBillsController $bills,
        private readonly TenantContext $tenant,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->may($request->user(), 'view');
        $input = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'status' => ['nullable', Rule::in(['pending', 'approved'])],
            'source' => ['nullable', 'string', 'max:60'],
        ]);

        $rows = FinancialResult::query()->with('company:id,name', 'franchise:id,name')
            ->when($input['from'] ?? null, fn ($q, $d) => $q->where('date', '>=', $d))
            ->when($input['to'] ?? null, fn ($q, $d) => $q->where('date', '<=', $d))
            ->when($input['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($input['source'] ?? null, fn ($q, $s) => $q->where('source', $s))
            ->orderByDesc('date')->limit(1000)->get()
            ->filter(fn (FinancialResult $r) => $this->inView($request->user(), $r));

        return response()->json(['data' => $rows->map(fn ($r) => $this->shape($r))->values()]);
    }

    /** Reads a photo into rows; keeps it ten minutes so saving can attach it. */
    public function read(Request $request): JsonResponse
    {
        $this->may($request->user(), 'create');
        $image = $request->validate(['image' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,heic', 'max:20480']])['image'];
        $bytes = (string) file_get_contents($image->getRealPath());
        $extension = strtolower($image->getClientOriginalExtension() ?: 'jpg');

        return response()->json(['data' => ResultReader::read($bytes, $extension) + ['image_token' => $this->bills->keep($bytes, $extension)]]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->may($user, 'create');
        $org = $this->tenant->idOrFail();
        $input = $request->validate($this->rules() + [
            'company_id' => ['nullable', 'uuid', Rule::exists('companies', 'id')->where('organization_id', $org)],
            'franchise_id' => ['nullable', 'uuid', Rule::exists('franchises', 'id')->where('organization_id', $org)],
            'image_token' => ['nullable', 'string', 'max:64'],
        ]);

        $company = isset($input['company_id']) ? Company::findOrFail($input['company_id']) : null;
        $franchise = isset($input['franchise_id']) ? Franchise::findOrFail($input['franchise_id']) : null;
        PlaceInView::assert($user, $company, $franchise, 'result');

        $result = $this->save($user, $company, $franchise, $input, 'app', $input['image_token'] ?? null);
        if ($user->hasPermission('financial_results.approve')) {
            $this->approve($user, $result);
        }

        return response()->json(['data' => $this->shape($result->fresh(['company', 'franchise']))], 201);
    }

    public function update(Request $request, FinancialResult $result): JsonResponse
    {
        $user = $request->user();
        $this->may($user, 'edit');
        abort_unless($this->inView($user, $result), Response::HTTP_NOT_FOUND);
        // An approved row is already in the reports; changing it needs the approve tick.
        abort_if($result->status === 'approved' && ! $user->hasPermission('financial_results.approve'), Response::HTTP_FORBIDDEN, 'Your role cannot change approved results.');

        $rules = array_map(fn ($r) => ['sometimes', ...array_values(array_diff($r, ['required']))], $this->rules());
        $result->update($request->validate($rules));
        $this->audit->log('financial_result.changed', $result, metadata: ['changed' => array_keys($result->getChanges())], actor: $user);

        return response()->json(['data' => $this->shape($result)]);
    }

    public function approveOne(Request $request, FinancialResult $result): JsonResponse
    {
        $this->may($request->user(), 'approve');
        abort_unless($this->inView($request->user(), $result), Response::HTTP_NOT_FOUND);
        $this->approve($request->user(), $result);

        return response()->json(['data' => $this->shape($result)]);
    }

    public function image(Request $request, FinancialResult $result)
    {
        $this->may($request->user(), 'view');
        abort_unless($this->inView($request->user(), $result) && $result->image_path !== null, Response::HTTP_NOT_FOUND);

        return Storage::disk($result->image_disk)->response($result->image_path);
    }

    public function destroy(Request $request, FinancialResult $result): JsonResponse
    {
        $this->may($request->user(), 'delete');
        abort_unless($this->inView($request->user(), $result), Response::HTTP_NOT_FOUND);
        $this->audit->log('financial_result.deleted', $result, metadata: ['date' => $result->date?->toDateString(), 'source' => $result->source], actor: $request->user());
        $result->delete();

        return response()->json(null, 204);
    }

    /** Saves one row as pending. Also the entry point for WhatsApp intake (channel "whatsapp"). */
    public function save(User $user, ?Company $company, ?Franchise $franchise, array $input, string $channel, ?string $imageToken = null): FinancialResult
    {
        // ponytail: shares the bill photo's ten-minute hold (FinancialBillsController::keep); one photo may make several rows, so get, not pull.
        $kept = $imageToken !== null ? Cache::get('financial-bill-image:'.$user->id.':'.$imageToken) : null;
        $sales = (int) ($input['sales_cents'] ?? 0);
        $cost = (int) ($input['cost_cents'] ?? 0);

        $result = FinancialResult::create([
            'company_id' => $franchise?->company_id ?? $company?->getKey(),
            'franchise_id' => $franchise?->getKey(),
            'source' => trim($input['source']),
            'date' => $input['date'],
            'area' => isset($input['area']) ? trim($input['area']) : null,
            'calls' => (int) ($input['calls'] ?? 0),
            'jobs' => (int) ($input['jobs'] ?? 0),
            'cost_cents' => $cost,
            'sales_cents' => $sales,
            'profit_cents' => (int) ($input['profit_cents'] ?? $sales - $cost),
            'image_disk' => $kept['disk'] ?? null,
            'image_path' => $kept['path'] ?? null,
            'image_sha256' => $kept['sha256'] ?? null,
            'channel' => $channel,
            'status' => 'pending',
            'created_by_user_id' => $user->getKey(),
        ]);
        $this->audit->log('financial_result.saved', $result, metadata: ['source' => $result->source, 'channel' => $channel], actor: $user);

        return $result;
    }

    public function approve(User $user, FinancialResult $result): void
    {
        if ($result->status === 'approved') {
            return;
        }
        $result->update(['status' => 'approved', 'approved_by_user_id' => $user->getKey(), 'approved_at' => now()]);
        $this->audit->log('financial_result.approved', $result, metadata: ['source' => $result->source, 'channel' => $result->channel], actor: $user);
        $this->events->emit('financial_result.approved', $result, [
            'source' => $result->source, 'date' => $result->date?->toDateString(), 'area' => $result->area,
            'calls' => $result->calls, 'jobs' => $result->jobs,
            'cost_cents' => $result->cost_cents, 'sales_cents' => $result->sales_cents, 'profit_cents' => $result->profit_cents,
        ], $user);
    }

    public function inView(User $user, FinancialResult $result): bool
    {
        $scope = ViewScope::forUser($user);

        return $result->franchise_id !== null
            ? $scope->coversFranchise($result->franchise_id)
            : $scope->isOrganization() || ($result->company_id !== null && $scope->coversCompany($result->company_id));
    }

    private function rules(): array
    {
        return [
            'source' => ['required', 'string', 'max:60'],
            'date' => ['required', 'date_format:Y-m-d'],
            'area' => ['nullable', 'string', 'max:120'],
            'calls' => ['nullable', 'integer', 'min:0'],
            'jobs' => ['nullable', 'integer', 'min:0'],
            'cost_cents' => ['nullable', 'integer'],
            'sales_cents' => ['nullable', 'integer'],
            'profit_cents' => ['nullable', 'integer'],
        ];
    }

    private function may(User $user, string $action): void
    {
        abort_unless($user->hasPermission("financial_results.{$action}"), Response::HTTP_FORBIDDEN, match ($action) {
            'view' => 'Your role cannot see results.',
            'approve' => 'Your role cannot approve results.',
            default => 'Your role cannot change results.',
        });
    }

    private function shape(FinancialResult $r): array
    {
        return [
            'id' => $r->id,
            'company_id' => $r->company_id,
            'franchise_id' => $r->franchise_id,
            'place' => $r->franchise?->name ?? $r->company?->name,
            'source' => $r->source,
            'date' => $r->date?->toDateString(),
            'area' => $r->area,
            'calls' => $r->calls,
            'jobs' => $r->jobs,
            'cost_cents' => $r->cost_cents,
            'sales_cents' => $r->sales_cents,
            'profit_cents' => $r->profit_cents,
            'has_image' => $r->image_path !== null,
            'channel' => $r->channel,
            'status' => $r->status,
            'approved_at' => $r->approved_at?->toIso8601String(),
        ];
    }
}
