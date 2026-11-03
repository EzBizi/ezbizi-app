<?php

namespace App\Http\Controllers\Api;

use App\Models\AirtableRecord;
use App\Models\FinancialSource;
use App\Models\FinancialTable;
use App\Support\Audit\AuditLogger;
use App\Support\Financials\AirtableMirror;
use App\Support\Scoping\ViewScope;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Financials → Settings → Airtable sources: the organization says which of its
 * Airtable tables is which part of its books. Organization configuration, so
 * it is changed from the organization view only (authority does not flow up).
 */
class FinancialSourcesController extends Controller
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly TenantContext $tenant,
    ) {}

    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermission('financials.view'), Response::HTTP_FORBIDDEN, 'Your role cannot see Financials.');

        return response()->json([
            'data' => FinancialSource::query()->with('company:id,name', 'franchise:id,name')->orderBy('role')->get()
                ->map(fn (FinancialSource $s) => $this->shape($s))->values(),
            'roles' => FinancialSource::ROLES,
            'field_keys' => FinancialSource::FIELD_KEYS,
            'marker_keys' => FinancialSource::MARKER_KEYS,
            'airtable_tables' => AirtableMirror::available(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->mayConfigure($request);
        $input = $this->validated($request);
        if (FinancialSource::query()->where('base_id', $input['base_id'])->where('table_id', $input['table_id'])
            ->where('franchise_id', $input['franchise_id'] ?? null)->exists()) {
            throw ValidationException::withMessages(['table_id' => 'That Airtable table already has a part for this place. Change that one instead.']);
        }
        $source = FinancialSource::create($input);
        $this->audit->log('financials.source_created', $source, actor: $request->user());

        return response()->json(['data' => $this->shape($source->load('company', 'franchise'))], 201);
    }

    public function update(Request $request, FinancialSource $source): JsonResponse
    {
        $this->mayConfigure($request);
        $source->fill($this->validated($request, partial: true))->save();
        $this->audit->logChange('financials.source_updated', $source, $request->user());

        return response()->json(['data' => $this->shape($source->load('company', 'franchise'))]);
    }

    public function destroy(Request $request, FinancialSource $source): JsonResponse
    {
        $this->mayConfigure($request);
        $this->audit->log('financials.source_deleted', $source, metadata: $source->only('base_id', 'table_id', 'role'), actor: $request->user());
        $source->delete();

        return response()->json(null, 204);
    }

    private function mayConfigure(Request $request): void
    {
        $user = $request->user();
        abort_unless($user->hasPermission('financials.edit'), Response::HTTP_FORBIDDEN, 'Your role cannot change Financials settings.');
        abort_unless(
            ViewScope::forUser($user)->isOrganization() && $user->canActOnOrganization($user->organization),
            Response::HTTP_FORBIDDEN,
            'Airtable sources belong to the whole organization. Switch to the organization view to change them.',
        );
    }

    private function validated(Request $request, bool $partial = false): array
    {
        $org = $this->tenant->idOrFail();
        $req = $partial ? 'sometimes' : 'required';
        $input = $request->validate([
            'base_id' => [$req, 'string', 'max:32'],
            'table_id' => [$req, 'string', 'max:32'],
            'role' => [$req, Rule::in(array_keys(FinancialSource::ROLES))],
            'company_id' => ['sometimes', 'nullable', 'uuid', Rule::exists('companies', 'id')->where('organization_id', $org)],
            'franchise_id' => ['sometimes', 'nullable', 'uuid', Rule::exists('franchises', 'id')->where('organization_id', $org)],
            'field_map' => ['sometimes', 'nullable', 'array'],
            'field_map.*' => ['nullable', 'string', 'max:255'],
            'filter_field' => ['sometimes', 'nullable', 'string', 'max:255', 'required_with:filter_value'],
            'filter_value' => ['sometimes', 'nullable', 'string', 'max:255', 'required_with:filter_field'],
            'valid_from' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'valid_until' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after_or_equal:valid_from'],
            'markers' => ['sometimes', 'nullable', 'array'],
            'markers.*' => ['nullable', 'string', 'max:255'],
        ]);

        if (isset($input['markers']) && ($unknown = array_diff(array_keys($input['markers']), FinancialSource::MARKER_KEYS)) !== []) {
            throw ValidationException::withMessages(['markers' => 'Unknown marker: '.implode(', ', $unknown).'.']);
        }
        if (isset($input['markers'])) {
            $input['markers'] = array_filter($input['markers'], fn ($v) => $v !== null && $v !== '');
        }

        if (isset($input['field_map']) && ($unknown = array_diff(array_keys($input['field_map']), FinancialSource::FIELD_KEYS)) !== []) {
            throw ValidationException::withMessages(['field_map' => 'Unknown column meaning: '.implode(', ', $unknown).'.']);
        }
        if (isset($input['field_map'])) {
            $input['field_map'] = array_filter($input['field_map'], fn ($v) => $v !== null && $v !== '');
        }

        return $input;
    }

    /**
     * "Copy now": the organization's Airtable copy is refreshed right after
     * this answers, then its mirrors. At most once every two minutes.
     */
    public function copyNow(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermission('financials.edit'), Response::HTTP_FORBIDDEN, 'Your role cannot change Financials settings.');
        $org = $this->tenant->idOrFail();
        if (! Cache::add("financials-copy-now:{$org}", true, 120)) {
            throw ValidationException::withMessages(['copy' => 'A copy started less than two minutes ago. Give it a moment.']);
        }
        $this->audit->log('financials.copy_requested', metadata: [], actor: $request->user());
        dispatch(function () use ($org) {
            Artisan::call('ezbizi:airtable-snapshot', ['--organization' => $org]);
            Artisan::call('ezbizi:financials-airtable-refresh');
        })->afterResponse();

        return response()->json(['data' => ['message' => 'Copying from Airtable now. Refresh in a minute to see it.']], 202);
    }

    private function shape(FinancialSource $s): array
    {
        $cut = FinancialTable::query()->where('source_base_id', $s->base_id)->where('source_table_id', $s->table_id)->cutOver()->exists();
        $copied = AirtableRecord::query()->where('base_id', $s->base_id)->where('table_id', $s->table_id)->max('fetched_at');

        return [
            // mirror: kept in step with Airtable; native: cut over to EzBizi.
            'kind' => $cut ? 'native' : 'mirror',
            'last_copied_at' => $cut || $copied === null ? null : now()->parse($copied)->toIso8601String(),
            // This place's own rows (a shared table's filter applied).
            'rows' => $s->rows()->count(),
            'id' => $s->id,
            'base_id' => $s->base_id,
            'table_id' => $s->table_id,
            'role' => $s->role,
            'company_id' => $s->company_id,
            'franchise_id' => $s->franchise_id,
            'place' => $s->franchise?->name ?? $s->company?->name,
            'filter_field' => $s->filter_field,
            'filter_value' => $s->filter_value,
            'field_map' => (object) ($s->field_map ?? []),
            'markers' => (object) ($s->markers ?? []),
            'valid_from' => $s->valid_from?->toDateString(),
            'valid_until' => $s->valid_until?->toDateString(),
        ];
    }
}
