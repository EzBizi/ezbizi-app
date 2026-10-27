<?php

namespace App\Http\Controllers\Api;

use App\Models\Franchise;
use App\Models\Job;
use App\Models\ServiceArea;
use App\Models\ServiceAreaZip;
use App\Models\WorkizJob;
use App\Models\WorkizTeamMember;
use App\Models\Customer;
use App\Models\Lead;
use App\Support\Audit\AuditLogger;
use App\Support\Automations\EventEmitter;
use App\Support\Jobs\BookJob;
use App\Support\Numbering\RecordNumberGenerator;
use App\Support\Workiz\WorkizJobWriter;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
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

        // A Workiz branch books into Workiz, which takes only its own lists (v2 /jobtypes, /adgroups: Leads, 638a8f4f2).
        $workiz = app(WorkizJobWriter::class)->choices($franchise->id, $zip ?: null);

        return response()->json(['data' => [
            'statuses' => Job::allowedStatuses($franchise),
            'workiz' => $workiz !== null,
            'job_types' => $workiz['job_types'] ?? [] ?: $used('JobType', 'job_type'),
            'job_sources' => $workiz['job_sources'] ?? [] ?: $used('JobSource', null),
            'service_areas' => $areas->map(fn ($a) => ['id' => $a->id, 'name' => $a->name, 'matches_zip' => in_array($a->id, $zipAreas, true)])->all(),
            'external_companies' => Job::query()->where('franchise_id', $franchise->id)->whereNotNull('external_company')
                ->distinct()->orderBy('external_company')->pluck('external_company')->all(),
            'custom_fields' => $fields,
            // Field techs on the branch's Workiz; covers_area when they work the chosen area.
            'technicians' => WorkizTeamMember::query()->where('franchise_id', $franchise->id)->where('is_active', true)->where('is_field_tech', true)
                ->orderBy('name')->get(['id', 'name', 'user_id', 'service_areas'])
                ->map(fn ($m) => ['id' => $m->id, 'name' => $m->name, 'user_id' => $m->user_id,
                    'covers_area' => $area === null ? null : in_array(mb_strtolower($area->name), array_map('mb_strtolower', (array) $m->service_areas), true)])
                ->all(),
        ]]);
    }

    /**
     * Fields Workiz's API does not take (measured writes: create with client,
     * address, times, type and source; assign a technician). They are kept in
     * EzBizi and the panel tags them "EzBizi only" (UI/UX, 2026-10-27).
     */
    public const EZBIZI_ONLY = ['company', 'phone_ext', 'more_phones', 'country', 'description', 'external_company', 'custom_fields', 'all_day', 'recurrence'];

    /**
     * POST /api/v1/jobs/book
     *
     * One booking from the Book panel: the customer (picked or made), the job
     * in EzBizi, then the job in the branch's Workiz with its technicians.
     * EzBizi's job stands whatever Workiz answers; Workiz's refusal comes back
     * for the panel to show, and POST jobs/{job}/book/workiz tries again.
     */
    public function store(Request $request, BookJob $book, RecordNumberGenerator $numbers, AuditLogger $audit, EventEmitter $events): JsonResponse
    {
        $input = $request->validate([
            'franchise_id' => ['required', 'uuid'],
            'lead_id' => ['nullable', 'uuid'],
            'customer_id' => ['nullable', 'uuid', 'required_without:customer.name'],
            'customer.name' => ['nullable', 'string', 'max:200'],
            'customer.company' => ['nullable', 'string', 'max:200'],
            'customer.phone' => ['nullable', 'string', 'max:40'],
            'customer.phone_ext' => ['nullable', 'string', 'max:10'],
            'customer.more_phones' => ['nullable', 'array', 'max:5'],
            'customer.more_phones.*' => ['string', 'max:40'],
            'customer.email' => ['nullable', 'email', 'max:200'],
            'location.address_line1' => ['nullable', 'string', 'max:200'],
            'location.address_line2' => ['nullable', 'string', 'max:200'],
            'location.city' => ['nullable', 'string', 'max:100'],
            'location.state' => ['nullable', 'string', 'max:2'],
            'location.postal_code' => ['nullable', 'string', 'max:20'],
            'location.country' => ['nullable', 'string', 'max:2'],
            'service_area_id' => ['required', 'uuid'],
            'title' => ['required', 'string', 'max:200'],
            'status' => ['nullable', 'string', 'max:60'],
            'job_type' => ['nullable', 'string', 'max:100'],
            'job_source' => ['nullable', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:20000'],
            'external_company' => ['nullable', 'string', 'max:150'],
            'custom_fields' => ['nullable', 'array'],
            'custom_fields.*' => ['nullable', 'string', 'max:500'],
            'scheduled' => ['required', 'boolean'],
            'start' => ['nullable', 'required_if:scheduled,true', 'date_format:Y-m-d H:i'],
            'end' => ['nullable', 'date_format:Y-m-d H:i', 'after:start'],
            'all_day' => ['nullable', 'boolean'],
            'recurrence' => ['nullable', 'array'],
            'recurrence.every' => ['required_with:recurrence', 'integer', 'min:1', 'max:52'],
            'recurrence.unit' => ['required_with:recurrence', 'in:day,week,month,year'],
            'recurrence.until' => ['nullable', 'date_format:Y-m-d'],
            'technician_ids' => ['nullable', 'array', 'max:10'],
            'technician_ids.*' => ['uuid'],
        ]);
        $franchise = Franchise::query()->findOrFail($input['franchise_id']);
        $this->authorize('create', [Job::class, $franchise]);
        $area = ServiceArea::query()->where('franchise_id', $franchise->id)->findOrFail($input['service_area_id']);
        if (isset($input['status']) && ! in_array($input['status'], Job::allowedStatuses($franchise), true)) {
            throw \Illuminate\Validation\ValidationException::withMessages(['status' => 'Not one of this organization\'s job statuses.']);
        }
        $lead = isset($input['lead_id']) ? Lead::query()->findOrFail($input['lead_id']) : null;
        if ($lead) {
            $this->authorize('update', $lead);
        }
        $members = WorkizTeamMember::query()->where('franchise_id', $franchise->id)->whereIn('id', $input['technician_ids'] ?? [])->get();
        $user = $request->user();
        // Workiz v2 makes no job without a type (measured, 2026-10-27): refused here, before anything is made.
        $writer = app(WorkizJobWriter::class);
        $connection = $writer->connection($franchise->id);
        if ($connection !== null && \App\Support\Workiz\WorkizV2::uses($connection) && blank($input['job_type'] ?? null)) {
            throw \Illuminate\Validation\ValidationException::withMessages(['job_type' => 'Pick a job type: this branch books into Workiz, which needs one.']);
        }

        $job = DB::transaction(function () use ($input, $franchise, $area, $lead, $book, $numbers, $audit, $events, $user) {
            $c = (array) ($input['customer'] ?? []);
            $loc = (array) ($input['location'] ?? []);
            $customer = isset($input['customer_id']) ? Customer::query()->findOrFail($input['customer_id']) : null;
            if ($customer === null) {
                $this->authorize('create', [Customer::class, $franchise]);
                // ponytail: a customer has no company, extension or second-phone columns; they ride in its notes.
                $notes = implode("\n", array_filter([
                    filled($c['company'] ?? null) ? 'Company: '.$c['company'] : null,
                    filled($c['phone_ext'] ?? null) ? 'Phone extension: '.$c['phone_ext'] : null,
                    ! empty($c['more_phones']) ? 'Other phones: '.implode(', ', $c['more_phones']) : null,
                ]));
                $customer = Customer::create([
                    'owning_franchise_id' => $franchise->getKey(),
                    'number' => $numbers->next(RecordNumberGenerator::CUSTOMERS, $franchise),
                    'name' => trim((string) $c['name']),
                    'phone' => $c['phone'] ?? null, 'email' => $c['email'] ?? null,
                    'address_line1' => $loc['address_line1'] ?? null, 'address_line2' => $loc['address_line2'] ?? null,
                    'city' => $loc['city'] ?? null, 'state' => isset($loc['state']) ? strtoupper($loc['state']) : null,
                    'postal_code' => $loc['postal_code'] ?? null, 'notes' => $notes ?: null,
                ]);
                $audit->log('customer.created', $customer, ['number' => $customer->number, 'from' => 'book_panel'], actor: $user);
                $events->emit('customer.created', $customer, ['after' => $customer->only(['name', 'phone', 'email', 'owning_franchise_id'])]);
            }

            $start = $input['scheduled'] && filled($input['start'] ?? null) ? $input['start'].':00' : null;
            $minutes = $start && filled($input['end'] ?? null)
                ? (int) \Illuminate\Support\Carbon::parse($start)->diffInMinutes(\Illuminate\Support\Carbon::parse($input['end'].':00')) : null;

            $job = $book($customer, $franchise, $input['title'], null, ['source' => 'book_panel', 'lead_id' => $lead?->id], array_filter([
                'status' => $input['status'] ?? null,
                'scheduled_for' => $start,
                'duration_minutes' => $minutes,
                'service_area_id' => $area->id,
                'job_type' => $input['job_type'] ?? null,
                'job_source' => $input['job_source'] ?? null,
                'description' => $input['description'] ?? null,
                'external_company' => $input['external_company'] ?? null,
                'custom_fields' => array_filter((array) ($input['custom_fields'] ?? []), fn ($v) => filled($v)) ?: null,
                'all_day' => (bool) ($input['all_day'] ?? false),
                'recurrence' => $input['recurrence'] ?? null,
            ], fn ($v) => $v !== null));

            if ($lead) {
                // Booked from a lead: what LeadsController::book does, so conversion rules still fire (UI/UX, 2026-10-27).
                $before = $lead->status;
                $lead->status = Lead::STATUS_BOOKED;
                $lead->job_id = $job->getKey();
                $audit->logChange('lead.converted', $lead);
                $lead->save();
                $events->emit('lead.converted', $lead, [
                    'before' => ['status' => $before],
                    'after' => ['status' => $lead->status, 'source' => $lead->source, 'job_id' => $job->getKey(), 'customer_id' => $job->customer_id],
                ]);
            }

            return $job;
        });

        return response()->json(['data' => [
            'job' => ['id' => $job->id, 'number' => $job->number],
            'workiz' => $this->toWorkiz($job, $members),
            'ezbizi_only' => self::EZBIZI_ONLY,
        ]], 201);
    }

    /** POST /api/v1/jobs/{job}/book/workiz {technician_ids[]}: try Workiz again after a refusal. */
    public function retryWorkiz(Request $request, string $job): JsonResponse
    {
        $input = $request->validate(['technician_ids' => ['nullable', 'array', 'max:10'], 'technician_ids.*' => ['uuid']]);
        $row = Job::query()->findOrFail($job);
        $this->authorize('update', $row);
        $members = WorkizTeamMember::query()->where('franchise_id', $row->franchise_id)->whereIn('id', $input['technician_ids'] ?? [])->get();

        return response()->json(['data' => ['job' => ['id' => $row->id, 'number' => $row->number], 'workiz' => $this->toWorkiz($row, $members)]]);
    }

    /**
     * The job into its branch's Workiz, then each technician. Create is once
     * (WorkizJobWriter finds a job it already made), so a retry only finishes.
     *
     * @return array{status: string, refused?: string, serial_id?: ?string, assigned?: list<string>}
     */
    private function toWorkiz(Job $job, $members): array
    {
        $writer = app(WorkizJobWriter::class);
        $made = $writer->create($job->fresh(['customer']), $job->job_type, $job->job_source);
        if ($made['job'] === null && $made['refused'] === null) {
            return ['status' => 'no_workiz'];
        }
        if ($made['refused'] !== null) {
            return ['status' => 'refused', 'refused' => $made['refused']];
        }
        // The area next, through Workiz v1 (v2 has none); then each technician, whom Workiz only takes inside an area they cover.
        $area = $job->serviceArea?->name;
        if ($area !== null) {
            $set = $writer->setServiceArea($made['job'], $area);
            if ($set['refused'] !== null) {
                return ['status' => 'refused', 'refused' => "Service area {$area}: {$set['refused']}", 'serial_id' => $made['job']->serial_id, 'assigned' => []];
            }
        }
        $assigned = [];
        foreach ($members as $m) {
            $sent = $writer->assign($made['job'], $m);
            if ($sent['refused'] !== null) {
                return ['status' => 'refused', 'refused' => "{$m->name}: {$sent['refused']}", 'serial_id' => $made['job']->serial_id, 'assigned' => $assigned];
            }
            $assigned[] = $m->name;
        }

        return ['status' => 'created', 'serial_id' => $made['job']->serial_id, 'assigned' => $assigned];
    }
}
