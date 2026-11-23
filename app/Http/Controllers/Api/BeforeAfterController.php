<?php

namespace App\Http\Controllers\Api;

use App\Models\Attachment;
use App\Models\InspectionReport;
use App\Support\Audit\AuditLogger;
use App\Support\Inspection\BeforeAfter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Clearing a report's before/after pairs for the website widget (Jose
 * 2026-11-03). Nothing is public until a person ticks that the pair shows no
 * faces and no house numbers.
 */
class BeforeAfterController extends Controller
{
    public function index(InspectionReport $inspectionReport): JsonResponse
    {
        return response()->json(['data' => array_map(fn (array $p) => [
            'id' => $p['after']->id,
            'label' => $p['label'],
            'before_id' => $p['before']->id,
            'after_id' => $p['after']->id,
            'approved_at' => $p['after']->web_approved_at,
        ], BeforeAfter::pairs($inspectionReport))]);
    }

    public function approve(Request $request, InspectionReport $inspectionReport, string $after, AuditLogger $audit): JsonResponse
    {
        $request->validate(['privacy_checked' => ['accepted']], [
            'privacy_checked.accepted' => 'Confirm the photos show no faces and no house numbers.',
        ]);

        return $this->stamp($inspectionReport, $after, now(), $audit, 'inspection.before_after.approved');
    }

    public function withdraw(InspectionReport $inspectionReport, string $after, AuditLogger $audit): JsonResponse
    {
        return $this->stamp($inspectionReport, $after, null, $audit, 'inspection.before_after.withdrawn');
    }

    private function stamp(InspectionReport $report, string $after, $at, AuditLogger $audit, string $action): JsonResponse
    {
        $pair = collect(BeforeAfter::pairs($report))->first(fn (array $p) => $p['after']->id === $after);
        abort_if($pair === null, 404, 'That photo is not one half of a before/after pair on this report.');

        /** @var Attachment $photo */
        $photo = $pair['after'];
        $photo->forceFill(['web_approved_at' => $at])->save();
        $audit->log($action, $report, metadata: ['after' => $photo->id, 'before' => $pair['before']->id]);

        return response()->json(['data' => ['id' => $photo->id, 'approved_at' => $photo->web_approved_at]]);
    }
}
