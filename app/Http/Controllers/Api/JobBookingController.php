<?php

namespace App\Http\Controllers\Api;

use App\Models\Franchise;
use App\Models\Job;
use App\Models\ServiceArea;
use App\Models\ServiceAreaZip;
use App\Models\WorkizJob;
use App\Models\SchedulingTechnician;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The shared Book panel (Jose, 2026-10-27: "I want all the options on Book ...
 * so we can eventually drop Workiz"), opened from Leads, Jobs, Customers and
 * Communication. Every list is the branch's own: nothing here names a type,
 * a source or an area.
 */
class JobBookingController extends Controller
{
    /**
     * GET /api/v1/jobs/book/options?franchise_id=&zip=&service_area_id=
     *
     * ponytail: job types, sources, custom fields and outside companies are the
     * ones the branch's jobs have used (Workiz's mirror and EzBizi's own), most
     * used first; Workiz's account lists replace them when its API gives them.
     */
    public function options(Request $request): JsonResponse
    {
        $input = $request->validate([
            'franchise_id' => ['required', 'uuid'],
            'zip' => ['nullable', 'string', 'max:10'],
            'service_area_id' => ['nullable', 'uuid'],
        ]);
        $franchise = Franchise::query()->findOrFail($input['franchise_id']);
        $this->authorize('create', [Job::class, $franchise]);

        $payloads = WorkizJob::query()->where('franchise_id', $franchise->id)->where('updated_at', '>=', now()->subYears(2))->get(['payload']);
        $used = function (string $workizKey, ?string $column) use ($payloads, $franchise) {
            $words = $payloads->map(fn ($r) => trim((string) ($r->payload[$workizKey] ?? '')));
            if ($column) {
                $words = $words->merge(Job::query()->where('franchise_id', $franchise->id)->whereNotNull($column)->pluck($column));
            }

            return $words->filter()->map(fn ($w) => trim($w))->countBy()->sortDesc()->keys()->values()->all();
        };

        $zip = substr(preg_replace('/\D/', '', (string) ($input['zip'] ?? '')), 0, 5);
        $areas = ServiceArea::query()->where('franchise_id', $franchise->id)->orderBy('name')->get(['id', 'name']);
        $zipAreas = strlen($zip) === 5 ? ServiceAreaZip::query()->where('franchise_id', $franchise->id)->where('zip', $zip)->pluck('service_area_id')->all() : [];
        $area = $areas->firstWhere('id', $input['service_area_id'] ?? null);

        $fields = $payloads->flatMap(fn ($r) => collect((array) ($r->payload['CustomFields'] ?? []))->filter(fn ($f) => is_array($f) && is_string($f['Name'] ?? null)))
            ->groupBy('Name')->map(function ($all, $name) {
                $values = $all->pluck('Value')->filter(fn ($v) => is_scalar($v) && trim((string) $v) !== '')->map(fn ($v) => trim((string) $v))->unique();

                return ['name' => $name, 'type' => $values->isEmpty() && $all->contains(fn ($f) => is_array($f['Value'] ?? null)) ? 'file' : 'text',
                    // A field with few distinct answers reads as a list (Real Wood Y/N, System Type).
                    'options' => $values->count() > 0 && $values->count() <= 12 ? $values->values()->all() : []];
            })->filter(fn ($f) => $f['type'] !== 'file')->values()->all();

        return response()->json(['data' => [
            'statuses' => Job::allowedStatuses($franchise),
            'job_types' => $used('JobType', 'job_type'),
            'job_sources' => $used('JobSource', null),
            'service_areas' => $areas->map(fn ($a) => ['id' => $a->id, 'name' => $a->name, 'matches_zip' => in_array($a->id, $zipAreas, true)])->all(),
            'external_companies' => Job::query()->where('franchise_id', $franchise->id)->whereNotNull('external_company')
                ->distinct()->orderBy('external_company')->pluck('external_company')->all(),
            'custom_fields' => $fields,
            // The branch's field techs on EzBizi's roster (Jose, 2026-10-27:
            // EzBizi is the master); covers_area when they work the chosen area.
            'technicians' => SchedulingTechnician::query()->with('areas:id')->where('franchise_id', $franchise->id)
                ->where('is_active', true)->where('is_field_tech', true)->orderBy('name')->get()
                ->map(fn (SchedulingTechnician $t) => ['id' => $t->id, 'name' => $t->name, 'user_id' => $t->user_id,
                    'covers_area' => $area === null ? null : $t->areas->contains('id', $area->id)])
                ->values()->all(),
        ]]);
    }
}
