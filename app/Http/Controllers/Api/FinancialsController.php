<?php

namespace App\Http\Controllers\Api;

use App\Models\Company;
use App\Models\FinancialBase;
use App\Models\FinancialField;
use App\Models\FinancialRecord;
use App\Models\FinancialTable;
use App\Models\FinancialView;
use App\Models\Franchise;
use App\Support\Audit\AuditLogger;
use App\Support\Automations\EventEmitter;
use App\Support\Financials\AirtableMirror;
use App\Support\Financials\CostSplit;
use App\Support\Financials\FieldType;
use App\Support\Financials\FinancialsHome;
use App\Support\Financials\SourceFigures;
use App\Support\Financials\Templates;
use App\Support\Inventory\InventoryVisibility;
use App\Support\Scoping\PlaceInView;
use App\Support\Scoping\ViewScope;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Financials — the Airtable replacement (Jose, 2026-10-14). Bases → tables →
 * typed fields, records and saved views. Every route authorizes against the
 * base its subject sits in (FinancialBasePolicy).
 */
class FinancialsController extends Controller
{
    // ponytail: a table loads whole; page it if a table outgrows this.
    private const MAX_RECORDS = 5000;

    private const VIEW_TYPES = ['grid', 'kanban', 'calendar', 'gallery', 'form', 'split'];

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly EventEmitter $events,
        private readonly TenantContext $tenant,
    ) {}

    /** The home's cards: areas of the books, then the organization's own bases. */
    public function home(Request $request): JsonResponse
    {
        $this->authorize('viewAny', FinancialBase::class);

        return response()->json(['data' => FinancialsHome::for($request->user())]);
    }

    // ── Bases ───────────────────────────────────────────────

    public function bases(Request $request): JsonResponse
    {
        $this->authorize('viewAny', FinancialBase::class);
        $user = $request->user();

        $bases = InventoryVisibility::constrain(FinancialBase::query(), $user, ViewScope::forUser($user))
            ->with(['tables:id,base_id,name,position', 'company:id,name', 'franchise:id,name'])
            ->orderBy('name')
            ->get();

        return response()->json(['data' => $bases->map(fn (FinancialBase $b) => $this->baseShape($b))]);
    }

    public function storeBase(Request $request): JsonResponse
    {
        $input = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'colour' => ['nullable', 'string', 'max:16'],
            'template' => ['nullable', Rule::in(array_keys(Templates::ALL))],
            'company_id' => ['nullable', 'uuid', Rule::exists('companies', 'id')->where('organization_id', $this->tenant->idOrFail())],
            'franchise_id' => ['nullable', 'uuid', Rule::exists('franchises', 'id')->where('organization_id', $this->tenant->idOrFail())],
        ]);

        $company = isset($input['company_id']) ? Company::findOrFail($input['company_id']) : null;
        $franchise = isset($input['franchise_id']) ? Franchise::findOrFail($input['franchise_id']) : null;
        PlaceInView::assert($request->user(), $company, $franchise, 'base');
        $this->authorize('create', [FinancialBase::class, $company, $franchise]);

        $base = DB::transaction(function () use ($input, $company, $franchise, $request) {
            $base = FinancialBase::create([
                'name' => $input['name'],
                'colour' => $input['colour'] ?? null,
                'company_id' => $company?->getKey(),
                'franchise_id' => $franchise?->getKey(),
                'created_by_user_id' => $request->user()->getKey(),
            ]);
            Templates::build($base, $input['template'] ?? 'blank');
            $this->audit->log('financials.base_created', $base, actor: $request->user());

            return $base;
        });

        return response()->json(['data' => $this->baseShape($base->load('tables', 'company', 'franchise'))], 201);
    }

    public function updateBase(Request $request, FinancialBase $base): JsonResponse
    {
        $this->authorize('update', $base);
        $base->fill($request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'colour' => ['sometimes', 'nullable', 'string', 'max:16'],
        ]))->save();
        $this->audit->logChange('financials.base_updated', $base, $request->user());

        return response()->json(['data' => $this->baseShape($base->load('tables', 'company', 'franchise'))]);
    }

    public function destroyBase(Request $request, FinancialBase $base): JsonResponse
    {
        $this->authorize('delete', $base);
        $this->audit->log('financials.base_deleted', $base, metadata: ['name' => $base->name], actor: $request->user());
        $base->delete();

        return response()->json(null, 204);
    }

    // ── Tables ──────────────────────────────────────────────

    public function storeTable(Request $request, FinancialBase $base): JsonResponse
    {
        $this->authorize('update', $base);
        $input = $request->validate(['name' => ['required', 'string', 'max:120']]);

        $table = DB::transaction(function () use ($base, $input, $request) {
            $table = Templates::blankTable($base, $input['name'], (int) $base->tables()->max('position') + 1);
            $this->audit->log('financials.table_created', $table, actor: $request->user());

            return $table;
        });

        return response()->json(['data' => $this->tableShape($table)], 201);
    }

    public function showTable(Request $request, FinancialTable $table): JsonResponse
    {
        $this->authorize('view', $table->base);

        return response()->json(['data' => $this->tableShape($table)]);
    }

    public function updateTable(Request $request, FinancialTable $table): JsonResponse
    {
        $this->authorize('update', $table->base);
        $table->fill($request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'position' => ['sometimes', 'integer', 'min:0'],
        ]))->save();
        $this->audit->logChange('financials.table_updated', $table, $request->user());

        return response()->json(['data' => ['id' => $table->id, 'name' => $table->name, 'position' => $table->position]]);
    }

    public function destroyTable(Request $request, FinancialTable $table): JsonResponse
    {
        $this->authorize('delete', $table->base);
        $this->audit->log('financials.table_deleted', $table, metadata: ['name' => $table->name], actor: $request->user());
        $table->delete();

        return response()->json(null, 204);
    }

    // ── Fields ──────────────────────────────────────────────

    public function storeField(Request $request, FinancialTable $table): JsonResponse
    {
        $this->authorize('update', $table->base);
        $this->refuseMirror($table);
        $input = $this->validateField($request, $table);

        $field = $table->fields()->create($input + ['position' => (int) $table->fields()->max('position') + 1]);
        $this->audit->log('financials.field_created', $field, actor: $request->user());

        return response()->json(['data' => $this->fieldShape($field)], 201);
    }

    public function updateField(Request $request, FinancialField $field): JsonResponse
    {
        $table = $field->table;
        $this->authorize('update', $table->base);
        $input = $this->validateField($request, $table, $field);

        DB::transaction(function () use ($field, $input, $table) {
            $field->fill($input)->save();

            if ($field->wasChanged(['type', 'options'])) {
                // Like Airtable: convert what converts, clear what does not.
                foreach ($table->records()->lazyById() as $record) {
                    $values = $record->values;
                    if (! array_key_exists($field->id, $values)) {
                        continue;
                    }
                    try {
                        $values[$field->id] = FieldType::coerce($field->type, $values[$field->id], $field->options ?? [], $field->name);
                    } catch (ValidationException) {
                        $values[$field->id] = null;
                    }
                    $record->values = array_filter($values, fn ($v) => $v !== null);
                    $record->save();
                }
            }
        });
        $this->audit->logChange('financials.field_updated', $field, $request->user());

        return response()->json(['data' => $this->fieldShape($field)]);
    }

    public function destroyField(Request $request, FinancialField $field): JsonResponse
    {
        $this->authorize('delete', $field->table->base);
        $this->refuseMirror($field->table);
        if ($field->is_primary) {
            throw ValidationException::withMessages(['field' => 'The first column names each record and cannot be deleted. Rename it instead.']);
        }
        $this->audit->log('financials.field_deleted', $field, metadata: ['name' => $field->name], actor: $request->user());
        $field->delete();

        return response()->json(null, 204);
    }

    // ── Records ─────────────────────────────────────────────

    public function storeRecord(Request $request, FinancialTable $table): JsonResponse
    {
        $this->authorize('update', $table->base);
        $this->refuseMirror($table);
        $values = $this->cleanValues($table, $request->validate(['values' => ['present', 'array']])['values']);

        $record = $table->records()->create([
            'values' => $values,
            'position' => (int) $table->records()->max('position') + 1,
            'created_by_user_id' => $request->user()->getKey(),
        ]);
        $this->audit->log('financials.record_created', $record, actor: $request->user());
        $this->events->emit('financial_record.created', $record, ['table' => $table->name]);

        return response()->json(['data' => $this->recordShape($record)], 201);
    }

    /** Partial: only the cells sent change. */
    public function updateRecord(Request $request, FinancialRecord $record): JsonResponse
    {
        $table = $record->table;
        $this->authorize('update', $table->base);
        $this->refuseMirror($table);
        $input = $request->validate(['values' => ['sometimes', 'array'], 'position' => ['sometimes', 'integer', 'min:0']]);

        if (isset($input['values'])) {
            $record->values = array_filter(
                array_replace($record->values ?? [], $this->cleanValues($table, $input['values'], keepNulls: true)),
                fn ($v) => $v !== null,
            );
        }
        if (isset($input['position'])) {
            $record->position = $input['position'];
        }
        $record->save();
        $this->audit->logChange('financials.record_updated', $record, $request->user());
        $this->events->emit('financial_record.updated', $record, ['table' => $table->name]);

        return response()->json(['data' => $this->recordShape($record)]);
    }

    /**
     * Many cells at once — a paste, a fill-down, a delete over a selection,
     * an undo (Jose, 2026-10-21: "like any excel sheet"). All or nothing: a
     * cell that does not fit its column refuses the whole change.
     */
    public function updateRecords(Request $request, FinancialTable $table): JsonResponse
    {
        $this->authorize('update', $table->base);
        $this->refuseMirror($table);
        $changes = $request->validate([
            'changes' => ['required', 'array', 'min:1', 'max:5000'],
            'changes.*.id' => ['required', 'uuid'],
            'changes.*.values' => ['required', 'array'],
        ])['changes'];

        $records = $table->records()->whereIn('id', array_column($changes, 'id'))->get()->keyBy('id');
        if ($records->count() !== count(array_unique(array_column($changes, 'id')))) {
            throw ValidationException::withMessages(['changes' => 'A row in the change is not in this table any more. Reload and try again.']);
        }

        $saved = DB::transaction(function () use ($changes, $records, $table, $request) {
            foreach ($changes as $change) {
                $record = $records[$change['id']];
                $record->values = array_filter(
                    array_replace($record->values ?? [], $this->cleanValues($table, $change['values'], keepNulls: true)),
                    fn ($v) => $v !== null,
                );
                if ($record->isDirty('values')) {
                    $record->save();
                    $this->audit->logChange('financials.record_updated', $record, $request->user());
                    $this->events->emit('financial_record.updated', $record, ['table' => $table->name]);
                }
            }

            return $records->values();
        });

        return response()->json(['data' => $saved->map(fn ($r) => $this->recordShape($r))->values()]);
    }

    public function destroyRecord(Request $request, FinancialRecord $record): JsonResponse
    {
        $this->authorize('delete', $record->table->base);
        $this->refuseMirror($record->table);
        $this->audit->log('financials.record_deleted', $record, metadata: ['values' => $record->values], actor: $request->user());
        $record->delete();

        return response()->json(null, 204);
    }

    // ── Views ───────────────────────────────────────────────

    public function storeView(Request $request, FinancialTable $table): JsonResponse
    {
        $this->authorize('update', $table->base);
        $input = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'type' => ['required', Rule::in(self::VIEW_TYPES)],
            'config' => ['nullable', 'array'],
        ]);
        $view = $table->views()->create($input + ['position' => (int) $table->views()->max('position') + 1]);
        $this->audit->log('financials.view_created', $view, actor: $request->user());

        return response()->json(['data' => $this->viewShape($view)], 201);
    }

    public function updateView(Request $request, FinancialView $view): JsonResponse
    {
        $this->authorize('update', $view->table->base);
        $view->fill($request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'config' => ['sometimes', 'nullable', 'array'],
        ]))->save();

        return response()->json(['data' => $this->viewShape($view)]);
    }

    public function destroyView(Request $request, FinancialView $view): JsonResponse
    {
        $this->authorize('delete', $view->table->base);
        if ($view->table->views()->count() === 1) {
            throw ValidationException::withMessages(['view' => 'A table keeps at least one view.']);
        }
        $this->audit->log('financials.view_deleted', $view, metadata: ['name' => $view->name], actor: $request->user());
        $view->delete();

        return response()->json(null, 204);
    }

    /** A split view's figures: each group's cost shared by job count, to the cent. */
    public function split(Request $request, FinancialView $view): JsonResponse
    {
        $this->authorize('view', $view->table->base);
        $range = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);
        if ($view->type !== 'split') {
            throw ValidationException::withMessages(['view' => 'Only a split view has a split.']);
        }

        return response()->json(['data' => CostSplit::run($view->table, (array) ($view->config ?? []), $range['from'] ?? null, $range['to'] ?? null)]);
    }

    /** Repair-team cost split per place, from the organization's mapped Airtable sources. */
    public function repairSplit(Request $request): JsonResponse
    {
        // Validated here, not in the helper, so the staff bot's catalogue can read the names.
        $input = $request->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
            'franchise_id' => ['nullable', 'uuid'],
        ]);

        return $this->sourceFigures($request, $input, fn ($from, $to, $place) => SourceFigures::repairSplit($from, $to, $place));
    }

    /** Refunds, disputes and returned checks per place and technician, from the mapped sources. */
    public function refunds(Request $request): JsonResponse
    {
        // Validated here, not in the helper, so the staff bot's catalogue can read the names.
        $input = $request->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
            'franchise_id' => ['nullable', 'uuid'],
        ]);

        return $this->sourceFigures($request, $input, fn ($from, $to, $place) => SourceFigures::refunds($from, $to, $place));
    }

    private function sourceFigures(Request $request, array $input, callable $figures): JsonResponse
    {
        $this->authorize('viewAny', FinancialBase::class);
        if (now()->parse($input['from'])->diffInDays(now()->parse($input['to'])) > 62) {
            throw ValidationException::withMessages(['to' => 'Ask for at most two months at a time.']);
        }
        $scope = ViewScope::forUser($request->user());

        // Only the places the current view reaches.
        $rows = array_values(array_filter(
            $figures($input['from'], $input['to'], $input['franchise_id'] ?? null),
            fn (array $row) => $scope->coversFranchise($row['franchise_id']),
        ));

        return response()->json(['data' => $rows, 'from' => $input['from'], 'to' => $input['to']]);
    }

    // ── CSV ─────────────────────────────────────────────────

    public function export(Request $request, FinancialTable $table): Response
    {
        $this->authorize('view', $table->base);
        $fields = $table->fields()->where('type', '!=', 'formula')->get();

        // Built inside the request: a streamed callback runs after the tenant
        // context has gone, and the scope would refuse the read.
        $out = fopen('php://temp', 'r+');
        fputcsv($out, $fields->pluck('name')->all());
        foreach ($table->records()->lazy() as $record) {
            fputcsv($out, $fields->map(function (FinancialField $f) use ($record) {
                $v = $record->values[$f->id] ?? '';

                return is_array($v) ? implode(', ', $v) : (is_bool($v) ? ($v ? 'true' : 'false') : $v);
            })->all());
        }
        rewind($out);

        return response(stream_get_contents($out), 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="'.str($table->name)->slug().'.csv"',
        ]);
    }

    /** CSV text in; a header that names no column becomes a new text column. */
    public function import(Request $request, FinancialTable $table): JsonResponse
    {
        $this->authorize('update', $table->base);
        $this->refuseMirror($table);
        $csv = $request->validate(['csv' => ['required', 'string', 'max:5000000']])['csv'];

        $rows = array_map('str_getcsv', preg_split('/\r\n|\n|\r/', trim($csv)));
        $headers = array_map('trim', array_shift($rows) ?? []);
        if ($headers === [] || count($rows) + $table->records()->count() > self::MAX_RECORDS) {
            throw ValidationException::withMessages(['csv' => 'The file is empty or would take the table past '.self::MAX_RECORDS.' records.']);
        }

        $count = DB::transaction(function () use ($table, $headers, $rows, $request) {
            $byName = $table->fields()->get()->keyBy(fn ($f) => mb_strtolower($f->name));
            $position = (int) $table->fields()->max('position');
            $columns = [];
            foreach ($headers as $i => $name) {
                if ($name === '') {
                    continue;
                }
                $columns[$i] = $byName[mb_strtolower($name)]
                    ??= $table->fields()->create(['name' => $name, 'type' => 'text', 'position' => ++$position]);
            }

            $next = (int) $table->records()->max('position');
            foreach ($rows as $line => $row) {
                $values = [];
                foreach ($columns as $i => $field) {
                    $raw = $row[$i] ?? null;
                    if ($field->type === 'multi_select' && is_string($raw)) {
                        $raw = array_values(array_filter(array_map('trim', explode(',', $raw))));
                    }
                    try {
                        $values[$field->id] = FieldType::coerce($field->type, $raw, $field->options ?? [], $field->name);
                    } catch (ValidationException $e) {
                        throw ValidationException::withMessages(['csv' => 'Row '.($line + 2).': '.collect($e->errors())->flatten()->first()]);
                    }
                }
                $table->records()->create([
                    'values' => array_filter($values, fn ($v) => $v !== null),
                    'position' => ++$next,
                    'created_by_user_id' => $request->user()->getKey(),
                ]);
            }
            $this->audit->log('financials.records_imported', $table, metadata: ['rows' => count($rows)], actor: $request->user());

            return count($rows);
        });

        return response()->json(['data' => ['imported' => $count, 'table' => $this->tableShape($table->refresh())]]);
    }

    // ── Airtable mirrors ────────────────────────────────────

    /** Tables in the Airtable copy that a base can mirror. */
    public function airtableTables(Request $request): JsonResponse
    {
        $this->authorize('viewAny', FinancialBase::class);

        return response()->json(['data' => AirtableMirror::available()]);
    }

    public function linkAirtable(Request $request, FinancialBase $base): JsonResponse
    {
        $this->authorize('update', $base);
        $input = $request->validate([
            'base_id' => ['required', 'string', 'max:32'],
            'table_id' => ['required', 'string', 'max:32'],
        ]);
        $table = AirtableMirror::link($base, $input['base_id'], $input['table_id']);
        $this->audit->log('financials.airtable_linked', $table, metadata: $input, actor: $request->user());

        return response()->json(['data' => $this->tableShape($table)], 201);
    }

    /**
     * Mirror an Airtable table without choosing a base: it goes into the
     * Financials base named after its Airtable base, made at the organization
     * level the first time. What a home chip's "Mirror it" calls.
     */
    public function mirrorAirtable(Request $request): JsonResponse
    {
        $input = $request->validate([
            'base_id' => ['required', 'string', 'max:32'],
            'table_id' => ['required', 'string', 'max:32'],
        ]);
        $this->authorize('create', [FinancialBase::class, null, null]);
        $base = AirtableMirror::baseFor($input['base_id'], $request->user()->id);

        return $this->linkAirtable($request, $base);
    }

    public function refreshAirtable(Request $request, FinancialTable $table): JsonResponse
    {
        $this->authorize('update', $table->base);
        if (! $table->isMirror()) {
            throw ValidationException::withMessages(['table' => 'This table is not linked to Airtable.']);
        }
        $done = AirtableMirror::refresh($table);

        return response()->json(['data' => ['refreshed' => $done, 'table' => $this->tableShape($table->refresh())]]);
    }

    // ── Helpers ─────────────────────────────────────────────

    private function refuseMirror(FinancialTable $table): void
    {
        if ($table->isMirror()) {
            throw ValidationException::withMessages(['table' => 'This table mirrors Airtable and refreshes every hour. Change it in Airtable.']);
        }
    }

    private function validateField(Request $request, FinancialTable $table, ?FinancialField $field = null): array
    {
        $input = $request->validate([
            'name' => [$field ? 'sometimes' : 'required', 'string', 'max:120',
                Rule::unique('financial_fields', 'name')->where('table_id', $table->id)->ignore($field?->id)],
            'type' => [$field ? 'sometimes' : 'required', Rule::in(FieldType::TYPES)],
            'options' => ['sometimes', 'nullable', 'array'],
            'options.choices' => ['sometimes', 'array', 'max:200'],
            'options.formula' => ['sometimes', 'string', 'max:2000'],
            'options.precision' => ['sometimes', 'integer', 'min:0', 'max:8'],
            'options.symbol' => ['sometimes', 'string', 'max:4'],
            'position' => ['sometimes', 'integer', 'min:0'],
        ], ['name.unique' => 'This table already has a column with that name.']);

        if ($field?->is_primary && isset($input['type']) && $input['type'] === 'formula') {
            throw ValidationException::withMessages(['type' => 'The first column cannot be a formula.']);
        }

        return $input;
    }

    private function cleanValues(FinancialTable $table, array $values, bool $keepNulls = false): array
    {
        $fields = $table->fields()->get()->keyBy('id');
        $clean = [];
        foreach ($values as $id => $value) {
            $field = $fields[$id] ?? throw ValidationException::withMessages(['values' => 'A cell names a column this table does not have.']);
            $clean[$id] = FieldType::coerce($field->type, $value, $field->options ?? [], $field->name);
        }

        return $keepNulls ? $clean : array_filter($clean, fn ($v) => $v !== null);
    }

    private function baseShape(FinancialBase $b): array
    {
        return [
            'id' => $b->id,
            'name' => $b->name,
            'colour' => $b->colour,
            'company_id' => $b->company_id,
            'franchise_id' => $b->franchise_id,
            'place' => $b->franchise?->name ?? $b->company?->name,
            'tables' => $b->tables->map(fn ($t) => ['id' => $t->id, 'name' => $t->name])->values(),
        ];
    }

    private function tableShape(FinancialTable $t): array
    {
        return [
            'id' => $t->id,
            'base_id' => $t->base_id,
            'name' => $t->name,
            'mirror' => $t->isMirror() ? ['base_id' => $t->source_base_id, 'table_id' => $t->source_table_id, 'synced_at' => $t->synced_at?->toIso8601String()] : null,
            'fields' => $t->fields()->get()->map(fn ($f) => $this->fieldShape($f))->values(),
            'views' => $t->views()->get()->map(fn ($v) => $this->viewShape($v))->values(),
            'records' => $t->records()->limit(self::MAX_RECORDS)->get()->map(fn ($r) => $this->recordShape($r))->values(),
        ];
    }

    private function fieldShape(FinancialField $f): array
    {
        return ['id' => $f->id, 'name' => $f->name, 'type' => $f->type, 'options' => (object) ($f->options ?? []), 'is_primary' => $f->is_primary];
    }

    private function recordShape(FinancialRecord $r): array
    {
        return ['id' => $r->id, 'values' => (object) ($r->values ?? []), 'created_at' => $r->created_at?->toIso8601String()];
    }

    private function viewShape(FinancialView $v): array
    {
        return ['id' => $v->id, 'name' => $v->name, 'type' => $v->type, 'config' => (object) ($v->config ?? [])];
    }
}
