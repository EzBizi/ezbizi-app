<?php

namespace App\Http\Controllers\Api;

use App\Models\Job;
use App\Models\JobLine;
use App\Models\Service;
use App\Support\Audit\AuditLogger;
use App\Support\Automations\EventEmitter;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * The work on a job (spec §7).
 *
 * A line is created FROM a service and then stops depending on it: name and
 * price are copied onto the row. Editing the catalogue afterwards is not an
 * edit to any job.
 *
 * There is no material line here. Materials are read from
 * `inventory_issues.job_id` and carry no money (§9), so nothing on a job holds
 * a cost and no total over jobs can leak one.
 */
class JobLinesController extends Controller
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly EventEmitter $events,
        private readonly TenantContext $tenant,
    ) {}

    /** POST /api/v1/jobs/{job}/lines */
    public function store(Request $request, Job $job): JsonResponse
    {
        $this->authorize('update', $job);
        $this->assertSeesMoney();

        $input = $request->validate([
            'service_id' => [
                'required', 'uuid',
                Rule::exists('services', 'id')->where('organization_id', $this->tenant->idOrFail()),
            ],
            'qty' => ['required', 'integer', 'min:1', 'max:100000'],
        ]);

        $service = Service::findOrFail($input['service_id']);

        $line = DB::transaction(function () use ($job, $service, $input) {
            $unit = (int) ($service->price_minor ?? 0);

            $line = $job->lines()->create([
                'organization_id' => $job->organization_id,
                'service_id' => $service->getKey(),
                'name' => $service->name,
                'unit_price_minor' => $unit,
                'qty' => $input['qty'],
                'amount_minor' => $unit * $input['qty'],
                'position' => (int) $job->lines()->max('position') + 1,
            ]);

            $job->recomputeTotal();

            $this->audit->log('job.line_added', $job, metadata: [
                'name' => $line->name,
                'total' => $job->fresh()->amount_minor,
            ]);

            // A line change is a change to the job. No per-line trigger: it
            // would fire three times for one edit and no rule hangs off it.
            $this->events->emit('job.updated', $job, [
                'after' => ['amount_minor' => $job->fresh()->amount_minor],
            ]);

            return $line;
        });

        return response()->json(['data' => $this->present($line)], Response::HTTP_CREATED);
    }

    /** PATCH /api/v1/jobs/{job}/lines/{line} */
    public function update(Request $request, Job $job, JobLine $line): JsonResponse
    {
        $this->authorize('update', $job);
        $this->assertSeesMoney();
        abort_unless($line->job_id === $job->getKey(), Response::HTTP_NOT_FOUND);

        $input = $request->validate([
            'qty' => ['required', 'integer', 'min:1', 'max:100000'],
        ]);

        DB::transaction(function () use ($job, $line, $input) {
            $line->forceFill([
                'qty' => $input['qty'],
                'amount_minor' => $line->unit_price_minor * $input['qty'],
            ])->save();

            $job->recomputeTotal();

            $this->audit->log('job.line_changed', $job, metadata: ['name' => $line->name]);
            $this->events->emit('job.updated', $job, [
                'after' => ['amount_minor' => $job->fresh()->amount_minor],
            ]);
        });

        return response()->json(['data' => $this->present($line->fresh())]);
    }

    /** DELETE /api/v1/jobs/{job}/lines/{line} */
    public function destroy(Job $job, JobLine $line): JsonResponse
    {
        $this->authorize('update', $job);
        $this->assertSeesMoney();
        abort_unless($line->job_id === $job->getKey(), Response::HTTP_NOT_FOUND);

        DB::transaction(function () use ($job, $line) {
            $this->audit->log('job.line_removed', $job, metadata: ['name' => $line->name]);
            $line->delete();
            $job->recomputeTotal();
            $this->events->emit('job.updated', $job, [
                'after' => ['amount_minor' => $job->fresh()->amount_minor],
            ]);
        });

        return response()->json(['message' => 'Line removed.']);
    }

    /** @return array<string, mixed> */
    private function present(JobLine $line): array
    {
        return [
            'id' => $line->id,
            'service_id' => $line->service_id,
            'name' => $line->name,
            'unit_price_minor' => $line->unit_price_minor,
            'qty' => $line->qty,
            'amount_minor' => $line->amount_minor,
            'position' => $line->position,
        ];
    }

    /**
     * The lines ARE a job's money: every line action needs the Financials
     * toggle (Jose, 2026-09-29). Money never travels with edit.
     */
    private function assertSeesMoney(): void
    {
        abort_unless(request()->user()->hasPermission('jobs.financials'), Response::HTTP_FORBIDDEN,
            'Money on jobs is hidden for your role.');
    }
}
