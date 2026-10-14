<?php

namespace App\Http\Controllers\Api;

use App\Models\Job;
use App\Models\SalesDeal;
use App\Support\Audit\AuditLogger;
use App\Support\Scoping\ViewScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Sales pipeline (Jose, 2026-10-14): every job "done pending approval" is a
 * deal to follow up, plus any job a salesperson has already worked (so a Sold
 * deal stays on the board after Workiz moves the job to Done).
 *
 * The quote is shown to anyone holding `sales.view`: chasing quotes is the
 * whole job, so the tab is the grant (unlike Jobs, which hides money behind
 * `jobs.financials`).
 */
class SalesController extends Controller
{
    public const PENDING = 'done pending approval';

    public function __construct(private readonly AuditLogger $audit) {}

    /** GET /api/v1/sales/deals */
    public function index(Request $request): JsonResponse
    {
        $franchises = ViewScope::forUser($request->user())->franchiseIds();
        $worked = SalesDeal::query()->pluck('job_id');

        // ponytail: no paging; A-1 had ~2k pending jobs on 2026-10-13. Page when it hurts.
        $jobs = Job::query()
            ->whereIn('franchise_id', $franchises)
            ->where(fn ($q) => $q->whereRaw('LOWER(TRIM(status)) = ?', [self::PENDING])->orWhereIn('id', $worked))
            ->with(['customer:id,name,phone,email,state', 'workizJob:id,job_id,serial_id,state'])
            ->latest()
            ->get();

        $deals = SalesDeal::query()->whereIn('job_id', $jobs->pluck('id'))->get()->keyBy('job_id');

        return response()->json(['data' => $jobs->map(fn (Job $job) => $this->present($job, $deals->get($job->id)))->values()]);
    }

    /** PUT /api/v1/sales/deals/{job} */
    public function update(Request $request, string $job): JsonResponse
    {
        $data = $request->validate([
            'stage'          => ['sometimes', Rule::in(SalesDeal::STAGES)],
            'urgency'        => ['sometimes', 'nullable', Rule::in(SalesDeal::URGENCIES)],
            'next_follow_up' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'notes'          => ['sometimes', 'nullable', 'string', 'max:5000'],
        ]);

        $model = Job::query()
            ->whereIn('franchise_id', ViewScope::forUser($request->user())->franchiseIds())
            ->findOrFail($job);

        $deal = SalesDeal::query()->firstOrNew(['job_id' => $model->id]);
        $deal->fill($data);
        if ($deal->isDirty('stage')) {
            $deal->sold_at = $deal->stage === 'sold' ? now() : null;
        }
        $deal->updated_by_user_id = $request->user()->id;

        $changes = $deal->getDirty();
        $deal->save();
        $this->audit->log('sales.deal_updated', $deal, changes: $changes);

        return response()->json(['data' => $this->present($model, $deal)]);
    }

    private function present(Job $job, ?SalesDeal $deal): array
    {
        return [
            'job_id'         => $job->id,
            'job_number'     => $job->workizJob?->serial_id ?? $job->number,
            'lead_date'      => $job->created_at?->toDateString(),
            'name'           => $job->customer?->name,
            'phone'          => $job->customer?->phone,
            'email'          => $job->customer?->email,
            'state'          => $job->customer?->state ?? $job->workizJob?->state,
            'quote_minor'    => $job->amount_minor,
            'currency'       => $job->currency,
            'job_status'     => $job->status,
            'stage'          => $deal?->stage ?? 'follow_up',
            'urgency'        => $deal?->urgency,
            'next_follow_up' => $deal?->next_follow_up?->toDateString(),
            'notes'          => $deal?->notes,
            'sold_at'        => $deal?->sold_at?->toIso8601String(),
        ];
    }
}
