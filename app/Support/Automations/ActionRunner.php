<?php

namespace App\Support\Automations;

use App\Models\AutomationEvent;
use App\Models\Job;
use App\Models\Vehicle;
use App\Support\Fleet\VehicleStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

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
        \App\Models\Job::class => [
            'title'            => 'Title',
            'description'      => 'Description',
            'status'           => 'Status',
            'scheduled_for'    => 'Date and time',
            'duration_minutes' => 'Duration in minutes',
            // Minor units, deliberately named as such. The job form takes
            // DOLLARS and converts (see the client's parseAmountMinor, and the
            // hundredfold error it was written to stop); a rule writes the
            // column directly, so calling it "Price" here would invite exactly
            // that mistake through the other door.
            'amount_minor'     => 'Amount in cents',
        ],
        \App\Models\Customer::class => [
            'name'          => 'Name',
            'email'         => 'Email',
            'phone'         => 'Phone',
            'notes'         => 'Notes',
            'is_active'     => 'Active',
            'address_line1' => 'Address line 1',
            'address_line2' => 'Address line 2',
            'city'          => 'City',
            'state'         => 'State',
            'postal_code'   => 'Postal code',
        ],
        \App\Models\Vehicle::class => [
            'plate'              => 'Plate',
            'colour'             => 'Colour',
            'odometer_miles'     => 'Odometer (miles)',
            'next_service_on'    => 'Next service date',
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
     * Field changes that mean something more specific than "updated".
     *
     * The controllers emit these; an automated change must emit them too, or
     * "when a job is completed" — the canonical rule the plan names — silently
     * does not fire when another rule is what completed it.
     */
    private const SECONDARY_TRIGGERS = [
        \App\Models\Job::class => [
            'status'        => 'job.status_changed',
            'scheduled_for' => 'job.scheduled',
        ],
        \App\Models\Customer::class => [],
    ];

    public function __construct(
        private readonly EventEmitter $emitter,
        private readonly OutboundWebhook $webhook,
        private readonly \App\Support\Audit\AuditLogger $audit,
        private readonly \App\Support\Notifications\NotificationDispatcher $notifications,
    ) {}

    /**
     * @return array{type: string, ok: bool, detail: string, skipped?: bool}
     */
    public function run(array $action, AutomationEvent $event, ?Model $subject, bool $isTest = false): array
    {
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
            'update_field'  => $this->updateField($action, $event, $subject),
            'change_status' => $this->changeStatus($action, $event, $subject),
            'webhook'       => $this->callWebhook($action, $event, $subject),
            'notify'        => $this->notify($action, $event, $subject),

            default => throw new InvalidArgumentException(
                "Unknown automation action '{$type}'. Actions are declared by modules "
                .'in code (§5.5) — a rule cannot invent one.'
            ),
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
                'type'   => 'update_field',
                'ok'     => false,
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
                'type'   => 'update_field',
                'ok'     => true,
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
            'type'   => 'update_field',
            'ok'     => true,
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
                'type'   => 'change_status',
                'ok'     => false,
                'detail' => 'A rule cannot change the status of that record.',
            ];
        }

        $next = VehicleStatus::tryFrom((string) $value);

        if ($next === null) {
            return [
                'type'   => 'change_status',
                'ok'     => false,
                'detail' => "'{$value}' is not a vehicle status.",
            ];
        }

        $before = $subject->status;

        if ($before === $next) {
            return [
                'type'   => 'change_status',
                'ok'     => true,
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
                        'after'  => ['status' => $next->value],
                    ],
                    metadata: ['caused_by_event' => $event->getKey(), 'trigger' => $event->event_key],
                );

                $subject->save();

                $payload = [
                    'old'    => $before->value,
                    'new'    => $next->value,
                    'before' => ['status' => $before->value],
                    'after'  => ['status' => $next->value],
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
            'type'   => 'change_status',
            'ok'     => true,
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
            'event'      => $event->event_key,
            'occurred_at' => $event->occurred_at->toIso8601String(),
            'subject'    => [
                'type' => $subject ? class_basename($subject) : null,
                'id'   => $event->subject_id,
            ],
            'payload'    => $event->payload,
            // So a receiver can discard a redelivery rather than acting twice.
            'idempotency_key' => $event->getKey(),
        ], $action['secret'] ?? null);

        return [
            'type'   => 'webhook',
            'ok'     => $result['ok'],
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
            'type'   => 'notify',
            'ok'     => true,
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
