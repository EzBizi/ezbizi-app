<?php

namespace App\Http\Controllers\Api;

use App\Models\Customer;
use App\Models\InspectionReport;
use App\Models\Job;
use App\Models\Organization;
use App\Support\Audit\AuditLogger;
use App\Support\Automations\EventEmitter;
use App\Support\Inspection\InspectionScope;
use App\Support\Inspection\LooksLikeJobNumber;
use App\Support\Inspection\ReportPdf;
use App\Support\Scoping\ViewScope;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Generated Reports — submitted work only, frozen, searchable, archivable.
 *
 * The body is the preset and answers stamped on the report, never the live
 * named inspection. A later publish must not restyle what was already submitted.
 */
class InspectionReportsController extends Controller
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly EventEmitter $events,
        private readonly ReportPdf $pdf,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', InspectionReport::class);

        $search = trim((string) $request->query('search', ''));
        $archived = $request->boolean('archived');
        $user = $request->user();
        $reachable = collect(ViewScope::forUser($user)->franchiseIds());

        $query = InspectionReport::query()
            ->with([
                'customer' => fn ($q) => $q->withTrashed(),
                'job' => fn ($q) => $q->withTrashed(),
                'submittedBy',
            ])
            ->orderByDesc('submitted_at');

        if (! $archived) {
            $query->whereNull('archived_at');
        }

        InspectionScope::constrainReports($query, $reachable->all());

        $message = null;

        if ($search !== '') {
            if (LooksLikeJobNumber::check($search)) {
                $jobIds = Job::withTrashed()
                    ->where('number', $search)
                    ->whereIn('franchise_id', $reachable)
                    ->pluck('id');

                if ($jobIds->isEmpty()) {
                    return response()->json(['data' => [], 'message' => 'Job not found']);
                }

                $query->whereIn('job_id', $jobIds);
            } else {
                $customerIds = Customer::withTrashed()
                    ->where('name', 'like', '%'.addcslashes($search, '%_\\').'%')
                    ->pluck('id');

                if ($customerIds->isEmpty()) {
                    return response()->json(['data' => [], 'message' => 'Client not found']);
                }

                $query->whereIn('customer_id', $customerIds);
            }

            $rows = $query->limit(50)->get();
            if ($rows->isEmpty()) {
                $message = LooksLikeJobNumber::check($search) ? 'Job not found' : 'Client not found';
            }
        } else {
            $rows = $query->limit(50)->get();
        }

        return response()->json([
            'data' => $rows->map(fn (InspectionReport $r) => $this->present($r))->values(),
            'message' => $message,
        ]);
    }

    public function show(Request $request, InspectionReport $inspectionReport): JsonResponse
    {
        $this->authorize('view', $inspectionReport);
        $this->assertReach($request, $inspectionReport);

        $inspectionReport->load([
            'customer' => fn ($q) => $q->withTrashed(),
            'job' => fn ($q) => $q->withTrashed(),
            'submittedBy',
            'version',
        ]);

        return response()->json([
            'data' => $this->present($inspectionReport, detailed: true),
        ]);
    }

    public function update(Request $request, InspectionReport $inspectionReport): JsonResponse
    {
        $this->authorize('update', $inspectionReport);

        throw ValidationException::withMessages([
            'answers' => 'A submitted inspection cannot be edited. Archive hides it; a new run is how a correction is made.',
        ]);
    }

    /**
     * GET .../reports/{id}/pdf — the report as a file somebody can send on.
     *
     * `view` and nothing more. A PDF of a report is the report: it contains
     * exactly what the screen already shows to anybody holding
     * `inspection.view`, so a second permission here would be a second answer to
     * a question InspectionReportPolicy already answers, and the two would
     * drift. `assertReach` is the same franchise rule `show` applies, for the
     * same reason — a report whose job is out of reach is "no such report".
     *
     * Not stored. See ReportDocument: nothing signs this page and no fingerprint
     * covers it, so there is no stored representation a fresh render could
     * disagree with, and keeping a copy would only be a second thing to purge.
     */
    public function pdf(Request $request, InspectionReport $inspectionReport): Response
    {
        $this->authorize('view', $inspectionReport);
        $this->assertReach($request, $inspectionReport);

        $pdf = $this->pdf->render($inspectionReport);

        $this->audit->log('inspection.downloaded', $inspectionReport);

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$this->filename($inspectionReport).'"',
            // The bytes are a customer's photographs and findings. Same two
            // locks the attachment endpoint uses, for the same reason.
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /**
     * The PDF by its signed link (InspectionReport::shareUrl), no session.
     *
     * An archived report is not served: archiving is how somebody takes a
     * link back once it has been pasted somewhere.
     */
    public function shared(TenantContext $tenant, string $report): Response
    {
        $found = $tenant->runWithoutTenant(fn () => InspectionReport::query()->find($report));
        abort_if($found === null || $found->archived_at !== null, Response::HTTP_NOT_FOUND);

        $organization = $tenant->runWithoutTenant(fn () => Organization::query()->find($found->organization_id));

        return $tenant->run($organization, function () use ($found) {
            $pdf = $this->pdf->render($found);
            $this->audit->log('inspection.downloaded', $found, metadata: ['by' => 'link']);

            return response($pdf, 200, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'inline; filename="'.$this->filename($found).'"',
                'X-Content-Type-Options' => 'nosniff',
                'Cache-Control' => 'private, no-store',
            ]);
        });
    }

    /**
     * ASCII, and short. A filename crosses an HTTP header, a Windows filesystem
     * and whatever mail client it is forwarded through, and a customer called
     * `O'Brien / 14 Maple` breaks at least one of those.
     */
    private function filename(InspectionReport $report): string
    {
        $stem = trim(preg_replace(
            '/[^A-Za-z0-9]+/', '-',
            $report->definition_name.' '.($report->customer?->name ?? ''),
        ) ?? '', '-');

        $date = $report->submitted_at?->format('Y-m-d') ?? 'report';

        return substr($stem === '' ? 'inspection' : $stem, 0, 60).'-'.$date.'.pdf';
    }

    public function archive(Request $request, InspectionReport $inspectionReport): JsonResponse
    {
        $this->authorize('archive', $inspectionReport);
        $this->assertReach($request, $inspectionReport);

        DB::transaction(function () use ($inspectionReport) {
            $inspectionReport->archive();
            $this->events->emit('inspection.archived', $inspectionReport);
            $this->audit->log('inspection.archived', $inspectionReport);
        });

        return response()->json([
            'data' => $this->present($inspectionReport->fresh()->load([
                'customer' => fn ($q) => $q->withTrashed(),
                'job' => fn ($q) => $q->withTrashed(),
                'submittedBy',
            ])),
        ]);
    }

    public function unarchive(Request $request, InspectionReport $inspectionReport): JsonResponse
    {
        $this->authorize('archive', $inspectionReport);
        $this->assertReach($request, $inspectionReport);

        $inspectionReport->unarchive();
        $this->audit->log('inspection.unarchived', $inspectionReport);

        return response()->json([
            'data' => $this->present($inspectionReport->fresh()->load([
                'customer' => fn ($q) => $q->withTrashed(),
                'job' => fn ($q) => $q->withTrashed(),
                'submittedBy',
            ])),
        ]);
    }

    /**
     * Where this report sits, against where the reader may look.
     *
     * ── What this used to do ────────────────────────────────────
     *
     *     if ($report->job_id === null) { return; }
     *
     * A report with no job was reachable from ANY branch — by id, as a PDF,
     * and by archive, which is a write. All four verbs come through here, so
     * that early return opened all four. A customer-only inspection is
     * ordinary: the picker allows starting one against a client with no job.
     *
     * ── Why it asks the model instead of re-deriving ────────────
     *
     * `automationScope()` already answers "which branch does this belong to",
     * job first and customer second, `withTrashed()` on both. It is the answer
     * the notification audience uses, and having the reader use a different
     * one is how the two drift into disagreeing about the same report. This
     * file has already paid for a duplicated franchise rule once —
     * `InspectionGlance` carried a copy of the list filter, bug included.
     *
     * A report that cannot say where it sits is reachable by nobody, which is
     * the fail-closed direction: the alternative is the early return that was
     * just removed.
     */
    private function assertReach(Request $request, InspectionReport $report): void
    {
        [$type, $franchiseId] = $report->automationScope();

        abort_if(
            $type !== 'franchise'
                || ! in_array($franchiseId, ViewScope::forUser($request->user())->franchiseIds(), true),
            404,
        );
    }

    /** @return array<string, mixed> */
    private function present(InspectionReport $report, bool $detailed = false): array
    {
        $row = [
            'id' => $report->id,
            'definition_name' => $report->definition_name,
            'layout_preset' => $report->layout_preset,
            'customer_name' => $report->customer?->name,
            'job_number' => $report->job?->number,
            'submitted_by_name' => $report->submittedBy?->name,
            'submitted_at' => $report->submitted_at?->toIso8601String(),
            'archived' => $report->archived_at !== null,
        ];

        if ($detailed) {
            $row['answers'] = $report->answers;
            $row['screens'] = $report->version?->screens ?? [];
            $row['intro'] = $report->version?->intro;
        }

        return $row;
    }
}
