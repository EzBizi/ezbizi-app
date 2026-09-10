<?php

namespace App\Http\Controllers\Api;

use App\Models\Company;
use App\Models\Franchise;
use App\Models\SavedReport;
use App\Support\Audit\AuditLogger;
use App\Support\Reports\ReportMap;
use App\Support\Reports\ReportRunner;
use App\Support\Scoping\PlaceInView;
use App\Support\Scoping\ViewScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Saved reports — list, build, run (spec 2026-09-10).
 *
 * ── A definition is validated by RUNNING it, at save ─────────────
 *
 * Not by a second copy of the runner's rules. A second copy is the thing that
 * drifts: the day somebody adds a filter operator, one of the two lists gets
 * it and the other refuses a report the runner would happily execute. So
 * `store` and `update` do a real run and turn any complaint into a 422.
 *
 * That also means a report cannot be saved unless the person saving it could
 * have run it — which is the property worth having, because the alternative is
 * a saved report that 500s for its author tomorrow.
 *
 * ── The field list is filtered by module permission ──────────────
 *
 * `fields` offers only resources whose own permission the caller holds. That
 * is not the whole story on disclosure — anyone with reports.build sees every
 * column of every module they can open — but it stops the picker advertising
 * a module the person cannot reach at all.
 */
class ReportsController extends Controller
{
    public function __construct(
        private readonly ReportRunner $runner,
        private readonly AuditLogger $audit,
    ) {}

    public function fields(Request $request): JsonResponse
    {
        $this->authorize('viewAny', SavedReport::class);

        $out = [];

        foreach (ReportMap::all() as $key => $resource) {
            if (! $request->user()->hasPermission($resource['permission'])) {
                continue;
            }

            $out[$key] = [
                'label'     => $resource['label'],
                'columns'   => $resource['columns'],
                'relations' => array_map(
                    fn (array $edge) => ['to' => $edge['to']],
                    $resource['relations'],
                ),
            ];
        }

        return response()->json(['data' => $out]);
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', SavedReport::class);

        $view = ViewScope::forUser($request->user());

        $reports = SavedReport::query()
            ->unless($view->isOrganization(), function ($query) use ($view) {
                $companyIds = $view->companyIds();

                if ($companyIds !== []) {
                    $query->whereIn('company_id', $companyIds);
                }
            })
            ->orderBy('name')
            ->get();

        return response()->json(['data' => $reports->map($this->present(...))->values()->all()]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);

        $company = $data['company_id'] === null
            ? null
            : Company::query()->findOrFail($data['company_id']);

        $franchise = $data['franchise_id'] === null
            ? null
            : Franchise::query()->findOrFail($data['franchise_id']);

        if ($company === null) {
            $this->authorize('createOnOrganization', SavedReport::class);
        } else {
            $this->authorize('create', [SavedReport::class, $company, $franchise]);
        }

        PlaceInView::assert($request->user(), $company, $franchise, 'report');

        $report = SavedReport::create($data);

        $this->audit->log('report.created', $report, [], ['name' => $report->name]);

        return response()->json(['data' => $this->present($report)], Response::HTTP_CREATED);
    }

    public function show(string $id): JsonResponse
    {
        $report = SavedReport::query()->findOrFail($id);
        $this->authorize('view', $report);

        return response()->json(['data' => $this->present($report)]);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $report = SavedReport::query()->findOrFail($id);
        $this->authorize('update', $report);

        $report->update($this->validated($request));

        $this->audit->logChange('report.updated', $report);

        return response()->json(['data' => $this->present($report->fresh())]);
    }

    public function destroy(string $id): JsonResponse
    {
        $report = SavedReport::query()->findOrFail($id);
        $this->authorize('delete', $report);

        $this->audit->log('report.deleted', $report, [], ['name' => $report->name]);

        $report->delete();

        return response()->json([], Response::HTTP_NO_CONTENT);
    }

    public function run(Request $request, string $id): JsonResponse
    {
        $report = SavedReport::query()->findOrFail($id);
        $this->authorize('view', $report);

        try {
            $result = $this->runner->run($report->definition, $request->user());
        } catch (InvalidArgumentException $e) {
            // A saved report whose map entry has since been withdrawn. Say so
            // rather than 500ing: the report is not broken, the thing it asked
            // about stopped being reportable.
            throw ValidationException::withMessages(['definition' => $e->getMessage()]);
        }

        // Reading widely is this screen's normal case, so a run is written
        // down. The empty third argument is $changes — a run alters nothing —
        // and the metadata is fourth.
        $this->audit->log('report.run', $report, [], [
            'name'      => $report->name,
            'row_count' => $result->count,
            'capped'    => $result->capped,
        ]);

        return response()->json(['data' => [
            'columns' => $result->columns,
            'rows'    => $result->rows,
            'capped'  => $result->capped,
            'count'   => $result->count,
            'cap'     => ReportRunner::ROW_CAP,
        ]]);
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'company_id'   => ['nullable', 'uuid'],
            'franchise_id' => ['nullable', 'uuid'],
            'name'         => ['required', 'string', 'max:120'],
            'description'  => ['nullable', 'string', 'max:2000'],
            'definition'   => ['required', 'array'],
        ]);

        $data['company_id'] ??= null;
        $data['franchise_id'] ??= null;
        $data['description'] ??= null;

        try {
            $this->runner->run($data['definition'], $request->user());
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['definition' => $e->getMessage()]);
        }

        return $data;
    }

    private function present(SavedReport $report): array
    {
        return [
            'id'           => $report->id,
            'name'         => $report->name,
            'description'  => $report->description,
            'company_id'   => $report->company_id,
            'franchise_id' => $report->franchise_id,
            'definition'   => $report->definition,
        ];
    }
}
