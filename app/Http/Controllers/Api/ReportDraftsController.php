<?php

namespace App\Http\Controllers\Api;

use App\Models\WhatsAppReportBatch;
use App\Support\Reports\Drafts\ReportChanges;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET  /api/v1/reports/drafts: the report drafts waiting for approval, with
 *      the corrections each has taken and the ones it can take.
 * POST /api/v1/reports/drafts/{batch}/changes {key, params}: one correction
 *      (ReportChanges), which rebuilds the preview for a new approval.
 * Both behind reports.correct (2026-10-20).
 */
class ReportDraftsController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(['data' => [
            'changes' => collect(ReportChanges::KEYS)->map(fn ($k, $key) => ['key' => $key, 'label' => $k[0], 'params' => $k[1]])->values(),
            'drafts' => WhatsAppReportBatch::query()->whereNull('approved_at')->latest('starts_on')->get()->map(fn (WhatsAppReportBatch $b) => [
                'id' => $b->id, 'period' => $b->period, 'starts_on' => $b->starts_on->toDateString(), 'ends_on' => $b->ends_on->toDateString(),
                'revision' => $b->revision, 'held' => $b->held_at !== null, 'changes' => $b->settings['changes'] ?? [],
            ]),
        ]]);
    }

    public function change(Request $request, WhatsAppReportBatch $batch, ReportChanges $changes): JsonResponse
    {
        $input = $request->validate([
            'key' => ['required', 'string', 'max:64'],
            'params' => ['present', 'array'],
        ]);

        $batch = $changes->apply($batch, $input['key'], $input['params'], $request->user());

        return response()->json(['data' => ['id' => $batch->id, 'changes' => $batch->settings['changes'] ?? []]]);
    }
}
