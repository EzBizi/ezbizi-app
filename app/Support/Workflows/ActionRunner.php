<?php

namespace App\Support\Workflows;

use App\Models\WorkflowEvent;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Performing one action from a workflow (plan §5.5).
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
    public function __construct(
        private readonly EventEmitter $emitter,
        private readonly OutboundWebhook $webhook,
    ) {}

    /**
     * @return array{type: string, ok: bool, detail: string, skipped?: bool}
     */
    public function run(array $action, WorkflowEvent $event, ?Model $subject, bool $isTest = false): array
    {
        $type = $action['type'] ?? null;

        if ($type === null) {
            throw new InvalidArgumentException('A workflow action needs a type.');
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
            'webhook'      => $this->callWebhook($action, $event, $subject),
            'notify'       => $this->notify($action, $event, $subject),

            default => throw new InvalidArgumentException(
                "Unknown workflow action '{$type}'. Actions are declared by modules "
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
    private function updateField(array $action, WorkflowEvent $event, ?Model $subject): array
    {
        if ($subject === null) {
            return ['type' => 'update_field', 'ok' => false, 'detail' => 'The record no longer exists.'];
        }

        $field = $action['field'] ?? null;
        $value = $action['value'] ?? null;

        if ($field === null) {
            throw new InvalidArgumentException('update_field needs a field.');
        }

        // Only fields the model itself declares fillable. Without this a rule
        // could set organization_id and walk a record into another tenant — the
        // BelongsToTenant guard would catch that one, but "the model decides what
        // is writable" is the rule that covers the next field nobody thought of.
        if (! $subject->isFillable($field)) {
            return [
                'type'   => 'update_field',
                'ok'     => false,
                'detail' => "'{$field}' is not a field a workflow may set on ".class_basename($subject).'.',
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
        $subject->save();

        $this->emitter->emit(
            eventKey: $this->updateEventFor($subject),
            subject: $subject,
            payload: ['before' => [$field => $before], 'after' => [$field => $value]],
            causedBy: $event,
        );

        return [
            'type'   => 'update_field',
            'ok'     => true,
            'detail' => "{$field}: ".json_encode($before).' → '.json_encode($value),
        ];
    }

    private function callWebhook(array $action, WorkflowEvent $event, ?Model $subject): array
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
     * Recorded rather than pushed for now: the notification module is not built,
     * and inventing a delivery path here would be the wrong place for it. The
     * run log says what would be sent, so nothing is silently lost when it is.
     */
    private function notify(array $action, WorkflowEvent $event, ?Model $subject): array
    {
        $message = $action['message'] ?? null;

        if ($message === null) {
            throw new InvalidArgumentException('notify needs a message.');
        }

        return [
            'type'   => 'notify',
            'ok'     => true,
            'detail' => 'Queued for delivery once notifications are built: '
                .$this->interpolate($message, $event, $subject),
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
    private function interpolate(string $template, WorkflowEvent $event, ?Model $subject): string
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
