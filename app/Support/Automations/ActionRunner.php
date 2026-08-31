<?php

namespace App\Support\Automations;

use App\Models\Automation;
use App\Models\AutomationEvent;
use App\Models\Customer;
use App\Models\Franchise;
use App\Models\Job;
use App\Models\PhoneCall;
use App\Models\PhoneNumber;
use App\Models\Task;
use App\Models\TaskBoard;
use App\Models\TaskGroup;
use App\Models\TrainingAssignment;
use App\Models\User;
use App\Models\Vehicle;
use App\Support\Audit\AuditLogger;
use App\Support\Fleet\VehicleStatus;
use App\Support\Notifications\NotificationDispatcher;
use App\Support\Numbering\RecordNumberGenerator;
use App\Support\Scoping\ViewScope;
use App\Support\Tasks\TaskUpdateWriter;
use App\Support\Tasks\TaskWriter;
use App\Support\TimeClock\TimeClock;
use App\Support\Training\AssignmentWriter;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Performing one action from an automation (plan §5.5).
 *
 * Every action returns a RESULT rather than throwing on a business failure —
 * "the webhook returned 500" is something the run log has to record, not
 * something that should abort the remaining actions. Genuine programming errors
 * (an unknown action type, a field that does not exist) still throw, because
 * those mean the rule itself is wrong.
 *
 * Actions that change data emit their own events through the emitter, carrying
 * the causing event so the cascade shares one root, one depth count and one
 * budget. That is what stops A→B→A.
 */
class ActionRunner
{
    /**
     * What a rule may set, per model. Explicit, not derived from $fillable.
     *
     * isFillable was the wrong guard. Customer::owning_franchise_id and
     * Job::franchise_id, customer_id and currency are all fillable — and the
     * HTTP API deliberately REFUSES all of them, because moving a customer's
     * accountability is a separate audited act (§5.2) and a job that changes
     * franchise rewrites two franchises' history. An automation could do
     * every one of those silently, skipping the authorization check, the
     * tenant-existence validation and the audit entry that exist for that.
     *
     * So: only fields where an automated change is meaningful and reversible.
     * Anything with its own endpoint belongs to that endpoint.
     *
     * ── Why the WORDING lives here too ───────────────────────────
     *
     * This is a security invariant, so it stays in code and is PUBLISHED rather
     * than made editable (§2.1). Publishing means a picker, and a picker needs
     * names: `scheduled_for` and `amount_minor` are wire names, and the person
     * choosing between them runs a chimney business.
     *
     * The client DOES name some of these on its own forms — jobs_screen calls
     * `status` "Status" and `amount_minor` "Price", customers_screen names
     * `name`, `email` and `phone` — but those are the words for a person filling
     * in one record by hand, and they are neither complete (nothing labels
     * `duration_minutes`) nor always right here: a rule writes raw minor units,
     * so "Price" would be an invitation to type 199.99 and get \$1.99. The data
     * export is the only other place that lists these columns and it prints the
     * keys raw. So the picker's wording had to be written somewhere.
     *
     * It lives beside the keys because a second array mapping field to name is
     * the one thing this whole arrangement exists to avoid. One array carries
     * both, which makes a field without a name impossible instead of merely
     * unlikely, and makes adding a field a single edit.
     *
     * @var array<class-string<Model>, array<string, string>> model => field => label
     */
    private const WRITABLE = [
        Job::class => [
            'title' => 'Title',
            'description' => 'Description',
            'status' => 'Status',
            'scheduled_for' => 'Date and time',
            'duration_minutes' => 'Duration in minutes',
            // Minor units, deliberately named as such. The job form takes
            // DOLLARS and converts (see the client's parseAmountMinor, and the
            // hundredfold error it was written to stop); a rule writes the
            // column directly, so calling it "Price" here would invite exactly
            // that mistake through the other door.
            'amount_minor' => 'Amount in cents',
        ],
        Customer::class => [
            'name' => 'Name',
            'email' => 'Email',
            'phone' => 'Phone',
            'notes' => 'Notes',
            'is_active' => 'Active',
            'address_line1' => 'Address line 1',
            'address_line2' => 'Address line 2',
            'city' => 'City',
            'state' => 'State',
            'postal_code' => 'Postal code',
        ],
        Vehicle::class => [
            'plate' => 'Plate',
            'colour' => 'Colour',
            'odometer_miles' => 'Odometer (miles)',
            'next_service_on' => 'Next service date',
            'next_service_miles' => 'Next service miles',
        ],
    ];

    /**
     * The fields a rule may set on a model, and what each one is called.
     *
     * Public because the save-time check and the catalogue both need it, and the
     * alternative — either of them keeping its own list — is how a rule came to
     * be accepted with `field: organization_id` and then refused on every single
     * run, forever. One list, three readers.
     *
     * @param  class-string|null  $model
     * @return array<string, string> field => label, empty when nothing is writable
     */
    public static function writableOn(?string $model): array
    {
        return $model === null ? [] : (self::WRITABLE[$model] ?? []);
    }

    /**
     * Which records a rule may move to a named status.
     *
     * Jobs already allow it via update_field. Vehicles go through
     * VehicleStatus::apply. Anything else returns a result at run time, so the
     * save-time check has to ask the same question or a Customer rule would
     * save and fail on every run.
     *
     * @param  class-string|null  $model
     */
    public static function canChangeStatus(?string $model): bool
    {
        return $model === Job::class || $model === Vehicle::class;
    }

    /**
     * Field changes that mean something more specific than "updated".
     *
     * The controllers emit these; an automated change must emit them too, or
     * "when a job is completed" — the canonical rule the plan names — silently
     * does not fire when another rule is what completed it.
     */
    private const SECONDARY_TRIGGERS = [
        Job::class => [
            'status' => 'job.status_changed',
            'scheduled_for' => 'job.scheduled',
        ],
        Customer::class => [],
    ];

    public function __construct(
        private readonly EventEmitter $emitter,
        private readonly OutboundWebhook $webhook,
        private readonly AuditLogger $audit,
        private readonly NotificationDispatcher $notifications,
        private readonly RecordNumberGenerator $numbers,
        private readonly TaskWriter $tasks,
        private readonly TaskUpdateWriter $updates,
        private readonly TimeClock $timeClock,
        private readonly AssignmentWriter $trainingAssignments,
    ) {}

    /**
     * @return array{type: string, ok: bool, detail: string, skipped?: bool}
     */
    public function run(
        array $action,
        AutomationEvent $event,
        ?Model $subject,
        bool $isTest = false,
        ?Automation $automation = null,
        int $actionIndex = 0,
    ): array {
        $type = $action['type'] ?? null;

        if ($type === null) {
            throw new InvalidArgumentException('An automation action needs a type.');
        }

        // Test mode performs nothing (§5.5). It reports what it WOULD do, which
        // is the only way to try a rule against real data without sending a
        // customer an email about a job from last March.
        if ($isTest) {
            return [
                'type' => $type,
                'ok' => true,
                'skipped' => true,
                'detail' => 'Test run — not performed.',
            ];
        }

        return match ($type) {
            'update_field' => $this->updateField($action, $event, $subject),
            'change_status' => $this->changeStatus($action, $event, $subject),
            'webhook' => $this->callWebhook($action, $event, $subject),
            'notify' => $this->notify($action, $event, $subject),

            // ActionDefinition::type() keeps everything after the first dot, so
            // phone.voicemail.mark_dealt arrives here as voicemail.mark_dealt.
            'voicemail.mark_dealt' => $this->markVoicemailDealt($event, $subject),
            'missed.create_job' => $this->createJobFromMissed($event, $subject),

            'task.create' => $this->createTask($action, $event, $automation),
            'task.assign_owner' => $this->updateTask($action, $event, $subject, $automation, 'owner_user_id'),
            'task.set_status' => $this->updateTask($action, $event, $subject, $automation, 'status_option_id'),
            'task.set_due_date' => $this->updateTask($action, $event, $subject, $automation, 'due_on'),
            'task.move_group' => $this->moveTask($action, $event, $subject, $automation),
            'task.add_update' => $this->addTaskUpdate($action, $event, $subject, $automation, $actionIndex),

            'time_clock.clock_in' => $this->punch($event, $subject, in: true),
            'time_clock.clock_out' => $this->punch($event, $subject, in: false),

            'training.assign' => $this->assignTraining($action, $event, $subject, $automation),

            default => throw new InvalidArgumentException(
                "Unknown automation action '{$type}'. Actions are declared by modules "
                .'in code (§5.5) — a rule cannot invent one.'
            ),
        };
    }

    private function createTask(array $action, AutomationEvent $event, ?Automation $automation): array
    {
        [$actor, $scope] = $this->taskContext($automation);
        $board = TaskBoard::query()->active()->find($action['board_id'] ?? null);
        if ($board === null) {
            return ['type' => 'task.create', 'ok' => false, 'detail' => 'The selected task board is unavailable.'];
        }
        $task = $this->tasks->create($actor, $board, $scope, [
            'title' => $action['title'] ?? null,
            'description' => $action['description'] ?? null,
            'group_id' => $action['group_id'] ?? null,
            'status_option_id' => $action['status_option_id'] ?? null,
            'owner_user_id' => $action['owner_user_id'] ?? null,
            'due_on' => $action['due_on'] ?? null,
        ], $event);

        return ['type' => 'task.create', 'ok' => true, 'detail' => "Created {$task->number}."];
    }

    private function updateTask(
        array $action,
        AutomationEvent $event,
        ?Model $subject,
        ?Automation $automation,
        string $field,
    ): array {
        if (! $subject instanceof Task) {
            return ['type' => $action['type'], 'ok' => false, 'detail' => 'This action requires a Task trigger.'];
        }
        [$actor, $scope] = $this->taskContext($automation);
        $value = match ($field) {
            'owner_user_id' => $action['owner_user_id'] ?? null,
            'status_option_id' => $action['status_option_id'] ?? null,
            'due_on' => $action['due_on'] ?? null,
        };
        $updated = $this->tasks->update(
            $actor,
            $subject,
            $scope,
            (int) $subject->fresh()->version,
            [$field => $value],
            causationId: $event->id,
            causedBy: $event,
        );

        return ['type' => $action['type'], 'ok' => true, 'detail' => "Updated {$updated->number}."];
    }

    private function moveTask(array $action, AutomationEvent $event, ?Model $subject, ?Automation $automation): array
    {
        if (! $subject instanceof Task || $subject->parent_task_id !== null) {
            return ['type' => 'task.move_group', 'ok' => false, 'detail' => 'Only a top-level Task can move between groups.'];
        }
        [$actor, $scope] = $this->taskContext($automation);
        $fresh = $subject->fresh();
        $source = TaskGroup::query()->active()->where('board_id', $fresh->board_id)->findOrFail($fresh->group_id);
        $destination = TaskGroup::query()->active()->where('board_id', $fresh->board_id)
            ->findOrFail($action['group_id'] ?? null);
        $moved = $this->tasks->reorder($actor, $fresh->board, $scope, [
            'task_id' => $fresh->id,
            'destination_group_id' => $destination->id,
            'expected_task_version' => (int) $fresh->version,
            'expected_source_order_version' => (int) $source->order_version,
            'expected_destination_order_version' => (int) $destination->order_version,
            'before_task_id' => null,
            'after_task_id' => null,
        ], $event);

        return ['type' => 'task.move_group', 'ok' => true, 'detail' => "Moved {$moved->number}."];
    }

    private function addTaskUpdate(
        array $action,
        AutomationEvent $event,
        ?Model $subject,
        ?Automation $automation,
        int $actionIndex,
    ): array {
        if (! $subject instanceof Task) {
            return ['type' => 'task.add_update', 'ok' => false, 'detail' => 'This action requires a Task trigger.'];
        }
        [$actor, $scope] = $this->taskContext($automation);
        $update = $this->updates->create(
            $actor,
            $subject,
            $scope,
            (string) ($action['body'] ?? ''),
            "auto:{$automation->id}:{$event->id}:{$actionIndex}",
            $event,
        );

        return ['type' => 'task.add_update', 'ok' => true, 'detail' => "Added update {$update->id}."];
    }

    /** @return array{0:User,1:ViewScope} */
    private function taskContext(?Automation $automation): array
    {
        if ($automation === null) {
            throw new InvalidArgumentException('A task automation action requires its rule identity.');
        }
        $actor = User::query()->where('is_active', true)->find($automation->created_by);
        if ($actor === null) {
            throw new InvalidArgumentException('The person who created this automation is no longer active.');
        }

        return [$actor, ViewScope::forStoredAutomationScope($actor, $automation->scope_type, $automation->scope_id)];
    }

    /**
     * Record that a voicemail has been picked up (dealt_at). EzBizi only — no Dial.
     *
     * @return array{type: string, ok: bool, detail: string}
     */
    /**
     * Clock the event's person in or out.
     *
     * Subject-bound, so the rule needs an event that is ABOUT a person — a
     * clock trigger, or `user.activated`. A rule wanting "clock out everybody
     * still open at 11pm" needs a scheduled trigger and a picked person, and
     * neither exists yet; refusing clearly beats a silently-empty run.
     *
     * TimeClock is called rather than reimplemented, so the organization
     * switch, the grant, the row lock, the unique index, the audit entry and
     * the emitted event are the same ones a person's punch goes through. The
     * asymmetry between the two is deliberate and belongs to that class: clock
     * IN refuses when the organization has the feature off or the person lacks
     * the grant, clock OUT only needs an open shift, because a shift left open
     * by somebody whose access changed still has to be closable (design §3.10).
     * A rule inherits both without restating either.
     */
    private function punch(AutomationEvent $event, ?Model $subject, bool $in): array
    {
        $type = $in ? 'time_clock.clock_in' : 'time_clock.clock_out';

        if (! $subject instanceof User) {
            return [
                'type' => $type,
                'ok' => false,
                'detail' => 'That record is not a person, so there is nobody to clock '
                    .($in ? 'in.' : 'out.'),
            ];
        }

        try {
            $in
                ? $this->timeClock->clockIn($subject, $event)
                : $this->timeClock->clockOut($subject, $event);
        } catch (ValidationException $e) {
            // "Already clocked in" and "not clocked in" are the ordinary result
            // of a rule firing on somebody whose state already matches, not a
            // fault. The run log says which, in the same sentence a person gets.
            return [
                'type' => $type,
                'ok' => false,
                'detail' => collect($e->errors())->flatten()->first() ?? $e->getMessage(),
            ];
        } catch (HttpException $e) {
            // clockIn aborts 403 when the person does not hold time_clock.view.
            return [
                'type' => $type,
                'ok' => false,
                'detail' => $e->getMessage(),
            ];
        }

        return [
            'type' => $type,
            'ok' => true,
            'detail' => $in
                ? "Clocked {$subject->name} in."
                : "Clocked {$subject->name} out.",
        ];
    }

    /**
     * Give a guide or a test to a person or a role, from a rule (training
     * task-9 brief).
     *
     * Calls the exact `App\Support\Training\AssignmentWriter` that
     * `AssignmentsController::store` calls — a rule and a person take the
     * identical write path, so reach-bounding (`AssignmentReach`) cannot be
     * bypassed by writing a rule instead of clicking a button. The ASSIGNER
     * is the automation's own creator, resolved the same way
     * [taskContext] already resolves it for every task action: a rule
     * reaches no further than the person who saved it could reach by hand.
     *
     * The assignee comes from the action's own config when a rule author set
     * one (`assignee_type`/`assignee_id` — the picker offers a specific
     * person or role, same as `owner_user_id` does for `task.assign_owner`),
     * and falls back to the triggering event's subject when it is a User —
     * the shape the trigger's own description names: "Paired with 'Account
     * activated' this is how an induction arrives on its own"
     * (ModuleServiceProvider).
     *
     * Both of `AssignmentWriter::create`'s failure modes are caught here
     * rather than left to bubble, per this class's own rule that a business
     * refusal is a RESULT and not a throw: a `ValidationException` for a
     * malformed target (neither/both of guide_id and test_id, or nobody
     * named), and an `AuthorizationException` — the same `Refusal` sentence
     * `AssignmentsController` would 403 with — for an assignee outside the
     * creator's own reach.
     */
    private function assignTraining(array $action, AutomationEvent $event, ?Model $subject, ?Automation $automation): array
    {
        [$actor] = $this->taskContext($automation);

        $assigneeType = $action['assignee_type'] ?? ($subject instanceof User ? TrainingAssignment::USER : null);
        $assigneeId = $action['assignee_id'] ?? ($subject instanceof User ? $subject->getKey() : null);

        try {
            $assignment = $this->trainingAssignments->create($actor, [
                'guide_id' => $action['guide_id'] ?? null,
                'test_id' => $action['test_id'] ?? null,
                'assignee_type' => $assigneeType,
                'assignee_id' => $assigneeId,
                'due_on' => $action['due_on'] ?? null,
            ], causedBy: $event);
        } catch (ValidationException $e) {
            return [
                'type' => 'training.assign',
                'ok' => false,
                'detail' => collect($e->errors())->flatten()->first() ?? $e->getMessage(),
            ];
        } catch (AuthorizationException $e) {
            return [
                'type' => 'training.assign',
                'ok' => false,
                'detail' => $e->getMessage(),
            ];
        }

        return [
            'type' => 'training.assign',
            'ok' => true,
            'detail' => "Assigned {$assigneeType}:{$assigneeId} training {$assignment->id}.",
        ];
    }

    private function markVoicemailDealt(AutomationEvent $event, ?Model $subject): array
    {
        if (! $subject instanceof PhoneCall) {
            return [
                'type' => 'voicemail.mark_dealt',
                'ok' => false,
                'detail' => 'That record is not a phone call, so it cannot be marked dealt with.',
            ];
        }

        if (! $subject->voicemail) {
            return [
                'type' => 'voicemail.mark_dealt',
                'ok' => false,
                'detail' => 'That call has no voicemail to mark dealt with.',
            ];
        }

        if ($subject->dealt_at !== null) {
            return [
                'type' => 'voicemail.mark_dealt',
                'ok' => true,
                'detail' => 'That voicemail was already marked dealt with.',
            ];
        }

        $subject->dealt_at = now();
        $subject->save();

        $this->audit->log(
            action: 'phone.voicemail_marked_dealt_by_automation',
            target: $subject,
            changes: ['after' => ['dealt_at' => $subject->dealt_at?->toIso8601String()]],
            metadata: ['caused_by_event' => $event->getKey(), 'trigger' => $event->event_key],
        );

        return [
            'type' => 'voicemail.mark_dealt',
            'ok' => true,
            'detail' => 'Marked the voicemail dealt with.',
        ];
    }

    /**
     * Open a job in reach titled from the caller number. Does not place a call.
     *
     * @return array{type: string, ok: bool, detail: string}
     */
    private function createJobFromMissed(AutomationEvent $event, ?Model $subject): array
    {
        if (! $subject instanceof PhoneCall) {
            return [
                'type' => 'missed.create_job',
                'ok' => false,
                'detail' => 'That record is not a phone call, so a job cannot be opened from it.',
            ];
        }

        $caller = (string) ($subject->from_e164 ?? '');
        if ($caller === '') {
            return [
                'type' => 'missed.create_job',
                'ok' => false,
                'detail' => 'That call has no caller number to title a job from.',
            ];
        }

        $franchise = $this->franchiseForCall($subject);
        if ($franchise === null) {
            return [
                'type' => 'missed.create_job',
                'ok' => false,
                'detail' => 'No franchise is in reach for that phone line, so a job cannot be opened.',
            ];
        }

        $statuses = Job::allowedStatuses($franchise);
        $status = $statuses[0] ?? null;
        if ($status === null) {
            return [
                'type' => 'missed.create_job',
                'ok' => false,
                'detail' => 'This organization has no job statuses configured.',
            ];
        }

        $job = DB::transaction(function () use ($subject, $caller, $franchise, $status, $event) {
            $customer = Customer::query()->where('phone', $caller)->first();

            if ($customer === null) {
                $customer = Customer::create([
                    'owning_franchise_id' => $franchise->id,
                    'number' => $this->numbers->next(RecordNumberGenerator::CUSTOMERS, $franchise),
                    'name' => $caller,
                    'phone' => $caller,
                ]);
            }

            $job = Job::create([
                'franchise_id' => $franchise->id,
                'customer_id' => $customer->id,
                'number' => $this->numbers->next(RecordNumberGenerator::JOBS, $franchise),
                'title' => $caller,
                'status' => $status,
                'currency' => strtoupper($franchise->currency ?: 'USD'),
                'description' => 'Opened from a missed call on '
                    .((string) ($subject->to_e164 ?: 'a phone line')).'.',
            ]);

            $this->audit->log(
                action: 'job.created_by_automation',
                target: $job,
                metadata: [
                    'caused_by_event' => $event->getKey(),
                    'trigger' => $event->event_key,
                    'from_call' => $subject->getKey(),
                ],
            );

            $this->emitter->emit(
                'job.created',
                $job,
                ['after' => $job->only([
                    'status', 'title', 'franchise_id', 'customer_id', 'amount_minor',
                ])],
                causedBy: $event,
            );

            return $job;
        });

        return [
            'type' => 'missed.create_job',
            'ok' => true,
            'detail' => "Opened job {$job->number} titled {$caller}.",
        ];
    }

    private function franchiseForCall(PhoneCall $call): ?Franchise
    {
        $number = $call->number_id !== null
            ? PhoneNumber::query()->find($call->number_id)
            : null;

        if ($number === null) {
            return null;
        }

        return match ($number->scope_type) {
            PhoneNumber::SCOPE_FRANCHISE => Franchise::query()->find($number->scope_id),
            PhoneNumber::SCOPE_COMPANY => Franchise::query()
                ->where('company_id', $number->scope_id)
                ->orderBy('name')
                ->first(),
            PhoneNumber::SCOPE_ORGANIZATION => Franchise::query()->orderBy('name')->first(),
            default => null,
        };
    }

    /**
     * Change a field on the record that triggered the rule.
     *
     * Emits an update event of its own, so a second rule can react to it — and
     * so the cascade is counted. Writing directly to the database instead would
     * make automation invisible to automation, which is a surprising and
     * hard-to-debug asymmetry.
     */
    private function updateField(array $action, AutomationEvent $event, ?Model $subject): array
    {
        if ($subject === null) {
            return ['type' => 'update_field', 'ok' => false, 'detail' => 'The record no longer exists.'];
        }

        $field = $action['field'] ?? null;
        $value = $action['value'] ?? null;

        if ($field === null) {
            throw new InvalidArgumentException('update_field needs a field.');
        }

        $writable = self::writableOn($subject::class);

        if (! array_key_exists($field, $writable)) {
            return [
                'type' => 'update_field',
                'ok' => false,
                'detail' => "'{$field}' is not a field an automation may set on "
                    .class_basename($subject).'. Allowed: '.implode(', ', array_keys($writable)).'.',
            ];
        }

        $before = $subject->getAttribute($field);

        if ($this->same($before, $value)) {
            // Not a failure, and worth recording: a rule that keeps setting a
            // field to what it already is would otherwise emit an event every
            // time and look like a loop.
            return [
                'type' => 'update_field',
                'ok' => true,
                'detail' => "'{$field}' was already ".json_encode($value).'.',
            ];
        }

        $subject->setAttribute($field, $value);

        // Audited like any other change (§2.2). An automation altering a
        // customer's email or a job's price left nothing in the audit log at
        // all, which is the one place somebody would look to find out what
        // happened.
        $this->audit->log(
            action: strtolower(class_basename($subject)).'.updated_by_automation',
            target: $subject,
            changes: ['before' => [$field => $before], 'after' => [$field => $value]],
            metadata: ['caused_by_event' => $event->getKey(), 'trigger' => $event->event_key],
        );

        $subject->save();

        $payload = ['before' => [$field => $before], 'after' => [$field => $value]];

        $this->emitter->emit(
            eventKey: $this->updateEventFor($subject),
            subject: $subject,
            payload: $payload,
            causedBy: $event,
        );

        // The specific trigger too, not only the generic one.
        $secondary = self::SECONDARY_TRIGGERS[$subject::class][$field] ?? null;

        if ($secondary !== null) {
            $this->emitter->emit($secondary, $subject, $payload, causedBy: $event);
        }

        return [
            'type' => 'update_field',
            'ok' => true,
            'detail' => "{$field}: ".json_encode($before).' → '.json_encode($value),
        ];
    }

    /**
     * Move a record to a named status.
     *
     * Jobs already allow this via update_field. Vehicles go through
     * VehicleStatus::apply so a rule cannot mark a rental sold without the same
     * 422 a person saving would see.
     */
    private function changeStatus(array $action, AutomationEvent $event, ?Model $subject): array
    {
        if ($subject === null) {
            return ['type' => 'change_status', 'ok' => false, 'detail' => 'The record no longer exists.'];
        }

        $value = $action['value'] ?? $action['status'] ?? null;

        if ($value === null) {
            throw new InvalidArgumentException('change_status needs a status.');
        }

        if ($subject instanceof Job) {
            $result = $this->updateField(
                ['field' => 'status', 'value' => $value],
                $event,
                $subject,
            );
            $result['type'] = 'change_status';

            return $result;
        }

        if (! $subject instanceof Vehicle) {
            return [
                'type' => 'change_status',
                'ok' => false,
                'detail' => 'A rule cannot change the status of that record.',
            ];
        }

        $next = VehicleStatus::tryFrom((string) $value);

        if ($next === null) {
            return [
                'type' => 'change_status',
                'ok' => false,
                'detail' => "'{$value}' is not a vehicle status.",
            ];
        }

        $before = $subject->status;

        if ($before === $next) {
            return [
                'type' => 'change_status',
                'ok' => true,
                'detail' => "status was already {$next->value}.",
            ];
        }

        $buyingBack = $before->isEnded() && $next->isOnRoad();

        try {
            DB::transaction(function () use ($subject, $next, $before, $action, $event, $buyingBack) {
                VehicleStatus::apply($subject, $next, $action['ownership'] ?? null);

                $this->audit->log(
                    action: 'vehicle.updated_by_automation',
                    target: $subject,
                    changes: [
                        'before' => ['status' => $before->value],
                        'after' => ['status' => $next->value],
                    ],
                    metadata: ['caused_by_event' => $event->getKey(), 'trigger' => $event->event_key],
                );

                $subject->save();

                $payload = [
                    'old' => $before->value,
                    'new' => $next->value,
                    'before' => ['status' => $before->value],
                    'after' => ['status' => $next->value],
                ];

                $this->emitter->emit('vehicle.status_changed', $subject, $payload, causedBy: $event);

                if ($buyingBack) {
                    $period = $subject->openOwnership();
                    if ($period !== null) {
                        $this->emitter->emit('vehicle.ownership_started', $subject, [
                            'after' => [
                                'kind' => $period->kind->value,
                                'counterparty' => $period->counterparty,
                                'started_on' => $period->started_on?->toDateString(),
                            ],
                        ], causedBy: $event);
                    }
                }
            });
        } catch (ValidationException $e) {
            $message = collect($e->errors())->flatten()->first() ?? $e->getMessage();

            return ['type' => 'change_status', 'ok' => false, 'detail' => $message];
        }

        return [
            'type' => 'change_status',
            'ok' => true,
            'detail' => 'status: '.json_encode($before->value).' → '.json_encode($next->value),
        ];
    }

    private function callWebhook(array $action, AutomationEvent $event, ?Model $subject): array
    {
        $url = $action['url'] ?? null;

        if ($url === null) {
            throw new InvalidArgumentException('webhook needs a url.');
        }

        $result = $this->webhook->send($url, [
            'event' => $event->event_key,
            'occurred_at' => $event->occurred_at->toIso8601String(),
            'subject' => [
                'type' => $subject ? class_basename($subject) : null,
                'id' => $event->subject_id,
            ],
            'payload' => $event->payload,
            // So a receiver can discard a redelivery rather than acting twice.
            'idempotency_key' => $event->getKey(),
        ], $action['secret'] ?? null);

        return [
            'type' => 'webhook',
            'ok' => $result['ok'],
            'detail' => $result['ok']
                ? "Delivered, HTTP {$result['status']}."
                : ($result['error'] ?? 'Failed.'),
        ];
    }

    /**
     * An in-app notification.
     *
     * The audience is whoever could have seen the record anyway — reach decides
     * it, exactly as it decides the job list (§4). Telling somebody about a
     * record they are not allowed to read is a leak dressed up as a feature: the
     * title alone usually gives the record away.
     */
    private function notify(array $action, AutomationEvent $event, ?Model $subject): array
    {
        $message = $action['message'] ?? null;

        if ($message === null) {
            throw new InvalidArgumentException('notify needs a message.');
        }

        $title = $this->interpolate($message, $event, $subject);

        $sent = $this->notifications->dispatch(
            title: $title,
            body: $action['body'] ?? null,
            subject: $subject,
            source: 'automation',
            eventId: $event->getKey(),
        );

        return [
            'type' => 'notify',
            'ok' => true,
            'detail' => $sent === 0
                // Not a failure. A rule scoped to a franchise nobody is assigned
                // to has nobody to tell, and saying so is more useful than a
                // success that quietly reached no one.
                ? "Nobody has reach over that record, so there was no one to tell: {$title}"
                : "Told {$sent} ".($sent === 1 ? 'person' : 'people').": {$title}",
        ];
    }

    /**
     * `{{ field }}` from the subject, and nothing else.
     *
     * Not a template engine, deliberately. Tenant-authored strings evaluated as
     * code is remote code execution wearing a friendly name; this only ever
     * substitutes attribute values, and an unknown field becomes an empty string
     * rather than an error nobody can act on.
     */
    private function interpolate(string $template, AutomationEvent $event, ?Model $subject): string
    {
        return preg_replace_callback('/\{\{\s*([a-z0-9_.]+)\s*\}\}/i', function ($m) use ($subject) {
            $value = $subject?->getAttribute($m[1]);

            return is_scalar($value) ? (string) $value : '';
        }, $template);
    }

    /**
     * The update trigger for a model, so an action's own event routes correctly.
     */
    private function updateEventFor(Model $subject): string
    {
        return strtolower(class_basename($subject)).'.updated';
    }

    private function same(mixed $a, mixed $b): bool
    {
        if ($a instanceof \DateTimeInterface) {
            $a = $a->format(DATE_ATOM);
        }

        return (string) (is_scalar($a) || $a === null ? $a : json_encode($a))
            === (string) (is_scalar($b) || $b === null ? $b : json_encode($b));
    }
}
