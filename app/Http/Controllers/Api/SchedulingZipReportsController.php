<?php

namespace App\Http\Controllers\Api;

use App\Models\ServiceArea;
use App\Models\ServiceAreaZip;
use App\Models\ServiceAreaZipRelease;
use App\Models\User;
use App\Models\ZipReport;
use App\Support\Notifications\NotificationDispatcher;
use App\Support\Scoping\ViewScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * "Report wrong ZIP" (Nelly Escobar, suggestion 01a20e5c, Jose-approved
 * 2026-11-24): anyone who sees Scheduling flags a ZIP that resolved to the
 * wrong area; whoever edits areas moves it from the list, which closes it.
 */
class SchedulingZipReportsController extends Controller
{
    /** GET /api/v1/scheduling/zip-reports: the open ones, newest first. */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', ServiceArea::class);
        $names = $this->reachable($request)->pluck('name', 'id');
        $rows = ZipReport::query()->whereNull('closed_at')->latest()->get()
            ->filter(fn ($r) => $r->reported_area_id === null || isset($names[$r->reported_area_id]))->values();
        $who = User::query()->whereIn('id', $rows->pluck('reported_by')->filter())->pluck('name', 'id');

        return response()->json(['data' => $rows->map(fn (ZipReport $r) => [
            'id' => $r->id,
            'zip' => $r->zip,
            'reported_area' => $r->reported_area_id ? ['id' => $r->reported_area_id, 'name' => $names[$r->reported_area_id] ?? null] : null,
            'suggested_area' => $r->suggested_area_id ? ['id' => $r->suggested_area_id, 'name' => $names[$r->suggested_area_id] ?? null] : null,
            'note' => $r->note,
            'reported_by' => $who[$r->reported_by] ?? null,
            'reported_at' => $r->created_at?->toIso8601String(),
        ])->all(), 'meta' => ['open' => $rows->count()]]);
    }

    /** POST /api/v1/scheduling/zip-reports {zip, reported_area_id?, suggested_area_id?, note?} */
    public function store(Request $request, NotificationDispatcher $notify): JsonResponse
    {
        $this->authorize('viewAny', ServiceArea::class);
        $data = $request->validate([
            'zip' => ['required', 'regex:/^\d{5}$/'],
            'reported_area_id' => ['nullable', 'uuid'],
            'suggested_area_id' => ['nullable', 'uuid'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);
        $areas = $this->reachable($request)->pluck('name', 'id');
        foreach (['reported_area_id', 'suggested_area_id'] as $k) {
            abort_if(isset($data[$k]) && ! isset($areas[$data[$k]]), 422, 'That service area is not one you can see.');
        }
        $report = ZipReport::create($data + ['reported_by' => $request->user()->id]);

        $title = "Wrong ZIP reported: {$report->zip}"
            .(isset($data['reported_area_id']) ? ' is in '.$areas[$data['reported_area_id']] : '')
            .(isset($data['suggested_area_id']) ? ', should be '.$areas[$data['suggested_area_id']] : '');
        // ponytail: every active user is asked; fine for a few hundred staff.
        $editors = User::query()->where('is_active', true)->get()
            ->filter(fn (User $u) => $u->hasPermission('scheduling.edit'))->pluck('id')->all();
        if ($editors !== []) {
            $notify->dispatch(title: $title, body: $report->note ?: 'Fix it in Scheduling > Settings > Wrong ZIPs.', userIds: $editors);
        }

        return response()->json(['data' => ['id' => $report->id]], 201);
    }

    /** POST /api/v1/scheduling/zip-reports/{report}/move {area_id}: the ZIP goes to that area only; closes the report. */
    public function move(Request $request, string $report): JsonResponse
    {
        $row = ZipReport::query()->whereNull('closed_at')->findOrFail($report);
        $data = $request->validate(['area_id' => ['required', 'uuid']]);
        $to = $this->reachable($request)->findOrFail($data['area_id']);
        $this->authorize('update', $to);

        DB::transaction(function () use ($request, $row, $to) {
            ServiceAreaZip::query()->where('zip', $row->zip)
                ->whereIn('service_area_id', $this->reachable($request)->select('id'))->delete();
            $zip = ServiceAreaZip::create(['franchise_id' => $to->franchise_id, 'service_area_id' => $to->id, 'zip' => $row->zip]);
            ServiceAreaZipRelease::claim([$row->zip], $zip->company_id);
            ZipReport::query()->whereNull('closed_at')->where('zip', $row->zip)
                ->update(['closed_at' => now(), 'closed_by' => $request->user()->id, 'moved_to_area_id' => $to->id]);
        });

        return response()->json(['data' => ['zip' => $row->zip, 'area' => ['id' => $to->id, 'name' => $to->name]]]);
    }

    /** POST /api/v1/scheduling/zip-reports/{report}/dismiss: the ZIP was right. */
    public function dismiss(Request $request, string $report): JsonResponse
    {
        $row = ZipReport::query()->whereNull('closed_at')->findOrFail($report);
        abort_unless($request->user()->hasPermission('scheduling.edit'), 403, 'Only somebody who edits service areas can close a report.');
        $row->update(['closed_at' => now(), 'closed_by' => $request->user()->id]);

        return response()->json(['data' => ['id' => $row->id]]);
    }

    private function reachable(Request $request): Builder
    {
        return ServiceArea::query()->whereIn('franchise_id', ViewScope::forUser($request->user())->franchiseIds());
    }
}
