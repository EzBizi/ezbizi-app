<?php

namespace App\Http\Controllers\Api;

use App\Models\Company;
use App\Models\Franchise;
use App\Models\SavedReport;
use App\Support\Audit\AuditLogger;
use App\Support\Automations\ReportSenders;
use App\Support\Reports\ReportCsv;
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
    private const PREVIEW_ROWS = 25;

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
                'label' => $resource['label'],
                /*
                 * CAST TO OBJECT, and it is not cosmetic.
                 *
                 * PHP serializes an empty array as `[]`, so a resource with no
                 * relations shipped `"relations": []` — a JSON ARRAY — while a
                 * resource with one shipped an object. The client cast it to a
                 * map and threw on the array, which in a release build is a
                 * blank grey panel with no error anywhere. Three of the four
                 * resources have no edges, so the builder was unusable.
                 *
                 * The map is a map at both ends now, whether or not it happens
                 * to be empty. ReportsApiTest pins the encoded shape, because
                 * the PHP value is identical either way and only the JSON says
                 * which this is.
                 */
                // Columns this person may not read are not offered either.
                'columns' => (object) array_filter(
                    $resource['columns'],
                    fn (array $column) => ! isset($column['permission'])
                        || $request->user()->hasPermission($column['permission']),
                ),
                'relations' => (object) array_map(
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
            // Downwards only: a place view lists its company's reports, never a
            // sibling's or the organization's (2026-10-16).
            ->when($view->companyIdsInView() !== null, fn ($query) => $query->whereIn('company_id', $view->companyIdsInView()))
            ->orderBy('name')
            ->get();

        /*
         * WHICH AUTOMATIONS SEND EACH REPORT (UI/UX + Automations, 2026-09-30),
         * so the library can say "sent Sundays at 8pm" rather than nothing.
         *
         * The rules are Automations' data: names and schedules only to a
         * reader holding automations.view, `sent_by: null` for everyone else
         * so "not yours to see" never reads as "no rule". Everyone gets
         * `sent_automatically`, which counts only a rule that would actually
         * run - one switched off, or auto-disabled after five refusals, does
         * not send, and a yes there would be a false promise.
         */
        $senders = ReportSenders::for(ReportSenders::REPORT_SAVED, $reports->pluck('id')->all());
        $mayReadRules = $request->user()->hasPermission('automations.view');

        return response()->json(['data' => $reports->map(fn (SavedReport $report) => $this->present($report) + [
            'sent_automatically' => collect($senders[$report->id] ?? [])
                ->contains(fn (array $rule) => $rule['is_active'] && $rule['disabled_at'] === null),
            'sent_by' => $mayReadRules ? ($senders[$report->id] ?? []) : null,
        ])->values()->all()]);
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
            'name' => $report->name,
            'row_count' => $result->count,
            'capped' => $result->capped,
        ]);

        return response()->json(['data' => [
            'columns' => $result->columns,
            'rows' => $result->rows,
            'capped' => $result->capped,
            'count' => $result->count,
            'cap' => ReportRunner::ROW_CAP,
            'total' => $result->total,
        ]]);
    }

    /**
     * A saved report as a CSV file (the builder brief, 2026-10-09: until now
     * CSV only came as an email attachment). The same rows as a run, written
     * by the same ReportCsv the email uses, and audited like a run.
     */
    public function csv(Request $request, string $id): Response
    {
        $report = SavedReport::query()->findOrFail($id);
        $this->authorize('view', $report);

        try {
            $result = $this->runner->run($report->definition, $request->user());
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['definition' => $e->getMessage()]);
        }

        $this->audit->log('report.run', $report, [], [
            'name' => $report->name,
            'row_count' => $result->count,
            'capped' => $result->capped,
            'format' => 'csv',
        ]);

        $file = trim((string) preg_replace('/[^A-Za-z0-9]+/', '-', $report->name), '-') ?: 'report';

        return response(ReportCsv::render($result), Response::HTTP_OK, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$file.'-'.now()->toDateString().'.csv"',
        ]);
    }

    /**
     * An UNSAVED definition, run for the builder's live table (Jose,
     * 2026-10-09: "i also want a preview view, as you select the items").
     * The same runner, so the same permission, column and row scoping as a
     * saved report's run; only the first PREVIEW_ROWS rows travel, with the
     * real count.
     *
     * Not audited, unlike run(): the builder asks on every tick of a field,
     * and a preview shows no more than the first screenful.
     * ponytail: the runner still reads up to ROW_CAP rows to count them; a
     * COUNT query of its own if previews get slow.
     */
    public function preview(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermission('reports.build'), Response::HTTP_FORBIDDEN, 'Building reports needs the Build permission.');

        $data = $request->validate(['definition' => ['required', 'array']]);

        try {
            $result = $this->runner->run($data['definition'], $request->user());
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['definition' => $e->getMessage()]);
        }

        return response()->json(['data' => [
            'columns' => $result->columns,
            'rows' => array_slice($result->rows, 0, self::PREVIEW_ROWS),
            'count' => $result->count,
            'capped' => $result->capped,
            'total' => $result->total,
        ]]);
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'company_id' => ['nullable', 'uuid'],
            'franchise_id' => ['nullable', 'uuid'],
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:2000'],
            'definition' => ['required', 'array'],
        ]);

        $data['company_id'] ??= null;
        $data['franchise_id'] ??= null;
        $data['description'] ??= null;

        /*
         * FILE ON THE CURRENT VIEW WHEN THE CLIENT NAMES NO PLACE.
         *
         * Sending neither id used to mean "file on the organization", which
         * PlaceInView refuses from anywhere but the group view — so standing
         * in a company, every save came back "File this on the company you are
         * looking at, not on the group." The builder never sent a place at
         * all, so reports could only be created from the organization view.
         *
         * The view is the right source: the server has already validated the
         * X-EzBizi-View header, so deriving from it cannot be talked into a
         * place the caller is not standing in — which sending it from the
         * client can. An EXPLICIT id is still honoured and still checked, so
         * this is a default rather than an override.
         */
        if (! $request->has('company_id') && ! $request->has('franchise_id')) {
            $view = ViewScope::forUser($request->user());

            if ($view->type === ViewScope::COMPANY) {
                $data['company_id'] = $view->id;
            } elseif ($view->type === ViewScope::FRANCHISE) {
                $franchise = Franchise::query()->find($view->id);

                // A place belongs to a company, and PlaceInView refuses a
                // franchise without one.
                $data['franchise_id'] = $franchise?->getKey();
                $data['company_id'] = $franchise?->company_id;
            }
        }

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
            'id' => $report->id,
            'name' => $report->name,
            'description' => $report->description,
            'company_id' => $report->company_id,
            'franchise_id' => $report->franchise_id,
            'definition' => $report->definition,
            // The map's own word for what the report counts ("Branches", not
            // "Franchises"), so the library's Covers column need not guess.
            'root_label' => ReportMap::get((string) ($report->definition['root'] ?? ''))['label'] ?? null,
        ];
    }
}
