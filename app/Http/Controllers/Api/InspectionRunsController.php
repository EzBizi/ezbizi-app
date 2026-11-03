<?php

namespace App\Http\Controllers\Api;

use App\Models\Customer;
use App\Models\InspectionDefinition;
use App\Models\InspectionReport;
use App\Models\InspectionRun;
use App\Models\Job;
use App\Models\Service;
use App\Support\Audit\AuditLogger;
use App\Support\Automations\EventEmitter;
use App\Support\Inspection\BoundAnswers;
use App\Support\Inspection\FieldType;
use App\Support\Inspection\InspectionAccess;
use App\Support\Inspection\OptionSet;
use App\Support\Inspection\RecommendedWork;
use App\Support\Inspection\RequiredFields;
use App\Support\Inspection\ScopeKey;
use App\Support\Scoping\ViewScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * New Inspection: search, start a personal draft, fill, submit.
 *
 * Drafts are personal. A second start of the same scope offers resume rather
 * than colliding with the unique index. Submit still works after unpublish;
 * start again does not — that is a new start.
 */
class InspectionRunsController extends Controller
{
    /** A job number (J-1042, JOB-00042, JOB-2026-00042) rather than a client name. */
    private const JOB_NUMBER = '/^[A-Za-z][A-Za-z0-9]*-\d+(?:-\d+)?$/';

    public function __construct(
        private readonly InspectionAccess $access,
        private readonly EventEmitter $events,
        private readonly AuditLogger $audit,
    ) {}

    public function search(Request $request): JsonResponse
    {
        // Read-only, so the Builder's Testing tab may pick a test client or
        // job too; starting a real inspection still needs create.
        if (! $request->user()->hasPermission('inspection.builder.view')) {
            $this->authorize('create', InspectionRun::class);
        }

        $q = trim((string) $request->query('q', $request->query('search', '')));
        if ($q === '') {
            return response()->json(['data' => []]);
        }

        $reachable = collect(ViewScope::forUser($request->user())->franchiseIds());

        if (preg_match(self::JOB_NUMBER, trim($q))) {
            $jobs = Job::query()
                ->with(['customer', 'franchise:id,name'])
                ->where('number', $q)
                ->whereIn('franchise_id', $reachable)
                ->limit(20)
                ->get();

            if ($jobs->isEmpty()) {
                return response()->json(['data' => [], 'message' => 'Job not found']);
            }

            return response()->json([
                'data' => $jobs->map(fn (Job $job) => [
                    'type' => 'job',
                    'id' => $job->id,
                    'label' => $job->number.($job->customer ? ' · '.$job->customer->name : ''),
                    'customer_id' => $job->customer_id,
                    'job_id' => $job->id,
                    'customer_name' => $job->customer?->name,
                    'job_number' => $job->number,
                    'address' => self::addressOf($job->customer),
                    'franchise' => $job->franchise ? ['id' => $job->franchise->id, 'name' => $job->franchise->name] : null,
                ])->values(),
            ]);
        }

        // A phone number, typed any way ("(305) 724-2199", "305.724.2199"),
        // matches on digits: phones are stored E.164, so the digits a person
        // types are a contiguous run of the stored ones. Jobs carry no phone
        // of their own — a job's phone is its customer's.
        $digits = preg_replace('/\D+/', '', $q) ?? '';
        $isPhone = strlen($digits) >= 4 && preg_match('/^[\d\s().+-]+$/', $q) === 1;

        $customers = Customer::query()
            ->with('owningFranchise:id,name')
            ->when(
                $isPhone,
                fn ($query) => $query->where('phone', 'like', '%'.$digits.'%'),
                fn ($query) => $query->where('name', 'like', '%'.addcslashes($q, '%_\\').'%'),
            )
            ->orderBy('name')
            ->limit(20)
            ->get();

        if ($customers->isEmpty()) {
            return response()->json(['data' => [], 'message' => 'Client not found']);
        }

        return response()->json([
            'data' => $customers->map(fn (Customer $c) => [
                'type' => 'customer',
                'id' => $c->id,
                'label' => $c->name,
                'customer_id' => $c->id,
                'job_id' => null,
                'customer_name' => $c->name,
                'job_number' => null,
                'address' => self::addressOf($c),
                'franchise' => $c->owningFranchise ? ['id' => $c->owningFranchise->id, 'name' => $c->owningFranchise->name] : null,
            ])->values(),
        ]);
    }

    /** One line, for a suggestion: "14 Maple St, Miami, FL 33180". */
    private static function addressOf(?Customer $c): ?string
    {
        if ($c === null) {
            return null;
        }

        $line = implode(', ', array_filter([
            $c->address_line1,
            $c->city,
            trim(($c->state ?? '').' '.($c->postal_code ?? '')),
        ], fn ($part) => is_string($part) && trim($part) !== ''));

        return $line === '' ? null : $line;
    }

    public function startable(Request $request): JsonResponse
    {
        $this->authorize('create', InspectionRun::class);

        $customerId = (string) $request->query('customer_id', '');
        $jobId = $request->query('job_id');
        $jobId = is_string($jobId) && $jobId !== '' ? $jobId : null;

        $offered = $this->access->startable($request->user());
        $scope = $customerId !== '' ? ScopeKey::for($customerId, $jobId) : null;

        $drafts = $scope === null
            ? collect()
            : InspectionRun::query()
                ->where('user_id', $request->user()->id)
                ->where('scope_key', $scope)
                ->get()
                ->keyBy('inspection_definition_id');

        $message = $offered->isEmpty()
            ? 'This organization has not offered any inspections to this role yet.'
            : null;

        return response()->json([
            'data' => $offered->map(fn (InspectionDefinition $d) => [
                'id' => $d->id,
                'name' => $d->name,
                'draft_id' => $drafts[$d->id]->id ?? null,
            ])->values(),
            'message' => $message,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', InspectionRun::class);

        $input = $request->validate([
            'customer_id' => ['required', 'uuid'],
            'job_id' => ['nullable', 'uuid'],
            'inspection_definition_id' => ['required', 'uuid'],
        ]);

        $customer = $this->findLiveCustomer($input['customer_id']);
        $job = $this->findLiveJob($input['job_id'] ?? null, $customer, $request);
        $definition = InspectionDefinition::query()->findOrFail($input['inspection_definition_id']);

        if (! $this->access->mayStart($request->user(), $definition)) {
            throw ValidationException::withMessages([
                'inspection_definition_id' => 'That inspection is not offered to this role, or is no longer published.',
            ]);
        }

        abort_if($definition->current_version_id === null, 422, 'That inspection has no published version to start.');

        $scope = ScopeKey::for($customer->id, $job?->id);

        $existing = InspectionRun::query()
            ->where('user_id', $request->user()->id)
            ->where('inspection_definition_id', $definition->id)
            ->where('scope_key', $scope)
            ->first();

        if ($existing !== null) {
            return response()->json([
                'data' => [
                    'choice' => 'resume',
                    'run' => $this->present($existing->load(['version', 'customer', 'job', 'definition'])),
                ],
            ], Response::HTTP_CONFLICT);
        }

        $run = $this->insertRun($request, $customer, $job, $definition, $scope);

        return response()->json(['data' => $this->present($run)], Response::HTTP_CREATED);
    }

    public function show(InspectionRun $inspectionRun): JsonResponse
    {
        $this->authorize('view', $inspectionRun);

        return response()->json([
            'data' => $this->present($inspectionRun->load(['version', 'customer', 'job', 'definition'])),
        ]);
    }

    public function update(Request $request, InspectionRun $inspectionRun): JsonResponse
    {
        $this->authorize('update', $inspectionRun);

        $input = $request->validate([
            'answers' => ['required', 'array'],
        ]);

        /*
         * Recommended work is resolved HERE, as it is picked, not at submit.
         *
         * Two reasons. A service that is not this organization's is refused
         * while the technician is still looking at the screen, rather than at
         * the end of a form they have spent twenty minutes on. And the answer
         * is stored as a SNAPSHOT — name and price as they are today — so the
         * report and the job line agree with what was on screen when the
         * recommendation was made.
         */
        $inspectionRun->loadMissing('version');

        $input['answers'] = self::resolveRecommendedWork(
            $inspectionRun->version?->screens ?? [],
            $input['answers'],
        );

        $inspectionRun->forceFill(['answers' => $input['answers']])->save();

        return response()->json([
            'data' => $this->present($inspectionRun->fresh()->load(['version', 'customer', 'job', 'definition'])),
        ]);
    }

    /**
     * Turn every `services` answer into priced snapshots.
     *
     * Walks nested fields too — the recommendation usually lives inside the
     * FAILING option, which is the whole point of that pattern.
     *
     * @param  list<array<string, mixed>>  $screens
     * @param  array<string, mixed>  $answers
     * @return array<string, mixed>
     */
    private static function resolveRecommendedWork(array $screens, array $answers): array
    {
        foreach ($screens as $si => $screen) {
            if (! is_array($screen)) {
                continue;
            }

            foreach ((is_array($screen['fields'] ?? null) ? $screen['fields'] : []) as $field) {
                if (! is_array($field)) {
                    continue;
                }

                foreach ([$field, ...OptionSet::revealedBy($field, $answers)] as $candidate) {
                    if (($candidate['type'] ?? '') !== FieldType::SERVICES) {
                        continue;
                    }

                    $key = (string) ($candidate['key'] ?? '');
                    if ($key === '' || ! array_key_exists($key, $answers)) {
                        continue;
                    }

                    $answers[$key] = RecommendedWork::resolve(
                        $answers[$key],
                        "answers.{$key}",
                    );
                }
            }
        }

        return $answers;
    }

    public function restart(Request $request, InspectionRun $inspectionRun): JsonResponse
    {
        $this->authorize('delete', $inspectionRun);

        $definition = $inspectionRun->definition;
        if ($definition === null || ! $this->access->mayStart($request->user(), $definition)) {
            throw ValidationException::withMessages([
                'inspection_definition_id' => 'Start again is a new start, so it needs a published inspection this role may still run.',
            ]);
        }

        $customer = $this->findLiveCustomer($inspectionRun->customer_id);
        $job = $this->findLiveJob($inspectionRun->job_id, $customer, $request);

        $run = DB::transaction(function () use ($request, $inspectionRun, $customer, $job, $definition) {
            $scope = $inspectionRun->scope_key;
            $inspectionRun->delete();
            $this->events->emit('inspection.discarded', $inspectionRun);
            $this->audit->log('inspection.discarded', $inspectionRun);

            return $this->insertRun($request, $customer, $job, $definition, $scope);
        });

        return response()->json(['data' => $this->present($run)], Response::HTTP_CREATED);
    }

    public function submit(Request $request, InspectionRun $inspectionRun): JsonResponse
    {
        $this->authorize('update', $inspectionRun);

        if (! $this->access->maySubmit($request->user(), $inspectionRun)) {
            throw ValidationException::withMessages([
                'answers' => 'Only the person who started this inspection can submit it.',
            ]);
        }

        $inspectionRun->load(['version', 'customer', 'job', 'definition']);
        $screens = $inspectionRun->version?->screens ?? [];
        RequiredFields::assertFilled($screens, $inspectionRun->answers ?? []);

        $answers = BoundAnswers::freeze(
            $inspectionRun->customer,
            $inspectionRun->job,
            $screens,
            $inspectionRun->answers ?? [],
        );

        $report = DB::transaction(function () use ($request, $inspectionRun, $answers) {
            $report = InspectionReport::create([
                'customer_id' => $inspectionRun->customer_id,
                'job_id' => $inspectionRun->job_id,
                'inspection_definition_id' => $inspectionRun->inspection_definition_id,
                'inspection_definition_version_id' => $inspectionRun->inspection_definition_version_id,
                'submitted_by' => $request->user()->id,
                'submitted_at' => now(),
                'answers' => $answers,
                'layout_preset' => $inspectionRun->version?->layout_preset ?? 'step_strip',
                'definition_name' => $inspectionRun->definition?->name ?? 'Inspection',
            ]);

            /*
             * BEFORE the delete, and that order is the whole of this.
             *
             * The run hard-deletes, and HasAttachments takes a hard-deleting
             * record's files with it — so a photograph still pointing at the run
             * on the next line is destroyed at the exact moment the inspection
             * becomes evidence. Re-pointing first leaves the run with no
             * attachments to take, which is why the hook needs no exception.
             *
             * A plain mass update and not a loop: nothing here reads the rows,
             * the ids in `answers` do not change, and the bytes are not touched
             * at all — the file stays exactly where it was written and only the
             * row that says what it belongs to moves.
             */
            /*
             * The recommendation becomes a quote, if there is a job to quote on.
             *
             * A customer-only inspection has no job and must not fail for it —
             * the finding is still on the report, which is what the technician
             * actually produced, and there is simply nowhere to put the price
             * yet. Appended rather than replacing: a second inspection on one
             * job adds to the quote, because two visits found two things.
             */
            if ($inspectionRun->job !== null) {
                $written = RecommendedWork::quoteOnto(
                    $inspectionRun->job,
                    RecommendedWork::fromAnswers(
                        $inspectionRun->version?->screens ?? [],
                        $answers,
                    ),
                );

                if ($written > 0) {
                    $this->audit->log('job.line_added', $inspectionRun->job, metadata: [
                        'lines' => $written,
                        'from_inspection' => $report->id,
                    ]);
                }
            }

            $inspectionRun->attachments()->update([
                'attachable_type' => $report->getMorphClass(),
                'attachable_id' => $report->getKey(),
            ]);

            $inspectionRun->delete();
            $this->events->emit('inspection.submitted', $report, [
                'report_id' => $report->id,
                'customer_id' => $report->customer_id,
                'job_id' => $report->job_id,
            ]);
            $this->audit->log('inspection.submitted', $report);

            // Before/After pills on the job's photo tab.
            \App\Support\Inspection\BeforeAfter::label($report);

            return $report;
        });

        return response()->json([
            'data' => [
                'id' => $report->id,
                'definition_name' => $report->definition_name,
                'layout_preset' => $report->layout_preset,
                'answers' => $report->answers,
                'customer_name' => $inspectionRun->customer?->name,
                'job_number' => $inspectionRun->job?->number,
                'submitted_at' => $report->submitted_at?->toIso8601String(),
            ],
        ]);
    }

    public function destroy(InspectionRun $inspectionRun): JsonResponse
    {
        $this->authorize('delete', $inspectionRun);

        DB::transaction(function () use ($inspectionRun) {
            $inspectionRun->delete();
            $this->events->emit('inspection.discarded', $inspectionRun);
            $this->audit->log('inspection.discarded', $inspectionRun);
        });

        return response()->json(['data' => ['discarded' => true]]);
    }

    private function insertRun(
        Request $request,
        Customer $customer,
        ?Job $job,
        InspectionDefinition $definition,
        string $scope,
    ): InspectionRun {
        $definition->loadMissing('currentVersion');
        $screens = $definition->currentVersion?->screens ?? [];
        $answers = BoundAnswers::freeze($customer, $job, $screens, []);

        $run = InspectionRun::create([
            'user_id' => $request->user()->id,
            'customer_id' => $customer->id,
            'job_id' => $job?->id,
            'inspection_definition_id' => $definition->id,
            'inspection_definition_version_id' => $definition->current_version_id,
            'scope_key' => $scope,
            'answers' => $answers,
        ]);

        $this->events->emit('inspection.started', $run);
        $this->audit->log('inspection.started', $run);

        return $run->load(['version', 'customer', 'job', 'definition']);
    }

    private function findLiveCustomer(string $id): Customer
    {
        $customer = Customer::query()->find($id);
        abort_if($customer === null, 422, 'That client is no longer available to inspect.');

        return $customer;
    }

    private function findLiveJob(mixed $id, Customer $customer, Request $request): ?Job
    {
        if (! is_string($id) || $id === '') {
            return null;
        }

        $job = Job::query()->find($id);
        abort_if($job === null, 422, 'That job is no longer available to inspect.');
        abort_if($job->customer_id !== $customer->id, 422, 'That job does not belong to this client.');

        $reachable = collect(ViewScope::forUser($request->user())->franchiseIds());
        abort_if(! $reachable->contains($job->franchise_id), 404);

        return $job;
    }

    /** @return array<string, mixed> */
    private function present(InspectionRun $run): array
    {
        return [
            'id' => $run->id,
            'customer_id' => $run->customer_id,
            'job_id' => $run->job_id,
            'customer_name' => $run->customer?->name,
            'job_number' => $run->job?->number,
            'inspection_definition_id' => $run->inspection_definition_id,
            'definition_name' => $run->definition?->name,
            'layout_preset' => $run->version?->layout_preset ?? 'step_strip',
            'screens' => $run->version?->screens ?? [],
            'intro' => $run->version?->intro,
            'answers' => $run->answers ?? [],
            'scope_key' => $run->scope_key,

            /*
             * The priced catalogue, carried WITH the run.
             *
             * A `services` field needs something to offer, and a picker that
             * fetched on tap would be a spinner on a roof with one bar of
             * signal. Sent only when the version actually asks for work, so an
             * inspection with no `services` field does not carry a catalogue it
             * will never show.
             */
            'services' => self::wantsServices($run->version?->screens ?? [])
                ? Service::query()
                    ->whereNotNull('price_minor')
                    ->orderBy('name')
                    ->get(['id', 'name', 'price_minor'])
                    ->map(fn (Service $s) => [
                        'id' => $s->getKey(),
                        'name' => $s->name,
                        'price_minor' => (int) $s->price_minor,
                    ])->values()
                : [],
        ];
    }

    /**
     * Does this version ask for recommended work anywhere?
     *
     * Nested fields included — the recommendation usually lives inside the
     * failing option, which is the whole point of that pattern.
     *
     * @param  list<array<string, mixed>>  $screens
     */
    private static function wantsServices(array $screens): bool
    {
        foreach ($screens as $screen) {
            foreach ((is_array($screen['fields'] ?? null) ? $screen['fields'] : []) as $field) {
                if (! is_array($field)) {
                    continue;
                }

                if (($field['type'] ?? '') === FieldType::SERVICES) {
                    return true;
                }

                foreach ((is_array($field['options'] ?? null) ? $field['options'] : []) as $option) {
                    $option = OptionSet::normalise($option);

                    foreach (($option['fields'] ?? []) as $nested) {
                        if (is_array($nested) && ($nested['type'] ?? '') === FieldType::SERVICES) {
                            return true;
                        }
                    }
                }
            }
        }

        return false;
    }
}
