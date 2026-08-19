<?php

namespace App\Http\Controllers\Api;

use App\Models\Customer;
use App\Models\InspectionReport;
use App\Models\Job;
use App\Support\Audit\AuditLogger;
use App\Support\Automations\EventEmitter;
use App\Support\Inspection\LooksLikeJobNumber;
use App\Support\Scoping\ViewScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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

        $query->where(function ($q) use ($reachable) {
            $q->whereNull('job_id')
                ->orWhereHas('job', fn ($jobs) => $jobs->withTrashed()->whereIn('franchise_id', $reachable));
        });

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

    private function assertReach(Request $request, InspectionReport $report): void
    {
        if ($report->job_id === null) {
            return;
        }

        $reachable = collect(ViewScope::forUser($request->user())->franchiseIds());
        $job = Job::withTrashed()->find($report->job_id);

        abort_if(
            $job === null || ! $reachable->contains($job->franchise_id),
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
