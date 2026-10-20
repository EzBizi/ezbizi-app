<?php

namespace App\Support\Automations;

use App\Models\AutomationEvent;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Does this event match the rule? (plan §5.5)
 *
 * Two modes over one model, the same two-tier idea as permissions: a simple UI
 * of field comparisons sitting on a tree that can nest AND and OR as deeply as
 * anyone needs.
 *
 *   {"all": [ {...}, {"any": [ {...}, {...} ]} ]}
 *   {"field": "status", "op": "eq", "value": "completed"}
 *
 * Every evaluation returns a TRACE as well as a verdict. §5.5 asks for "trigger,
 * conditions evaluated, actions taken, outcome" — and "the rule did not match"
 * is useless to whoever wrote the rule without knowing which clause failed.
 */
class ConditionEvaluator
{
    /**
     * Deliberately a fixed list.
     *
     * Operators are platform invariants (§2.1 category 1), not tenant
     * configuration. A runtime-editable operator set means arbitrary expressions
     * evaluated against arbitrary fields, which is where a config system turns
     * into an injection surface.
     */
    private const OPERATORS = [
        'eq', 'neq', 'in', 'not_in', 'gt', 'gte', 'lt', 'lte',
        'contains', 'not_contains', 'is_empty', 'is_not_empty',
        'changed', 'changed_to', 'changed_from',
    ];

    /**
     * @return array{matched: bool, trace: array}
     */
    public function evaluate(?array $conditions, AutomationEvent $event, ?Model $subject): array
    {
        // No conditions is not an error — "whenever a job is created" is a
        // perfectly good rule, and forcing a placeholder clause would just get
        // filled with something meaningless.
        if ($conditions === null || $conditions === []) {
            return ['matched' => true, 'trace' => ['reason' => 'no conditions']];
        }

        $trace = $this->node($conditions, $event, $subject);

        return ['matched' => $trace['matched'], 'trace' => $trace];
    }

    private function node(array $node, AutomationEvent $event, ?Model $subject): array
    {
        if (isset($node['all'])) {
            $children = array_map(fn ($c) => $this->node($c, $event, $subject), $node['all']);

            return [
                'type'     => 'all',
                'matched'  => ! in_array(false, array_column($children, 'matched'), strict: true),
                'children' => $children,
            ];
        }

        if (isset($node['any'])) {
            $children = array_map(fn ($c) => $this->node($c, $event, $subject), $node['any']);

            return [
                'type'     => 'any',
                'matched'  => in_array(true, array_column($children, 'matched'), strict: true),
                'children' => $children,
            ];
        }

        if (isset($node['not'])) {
            $child = $this->node($node['not'], $event, $subject);

            return ['type' => 'not', 'matched' => ! $child['matched'], 'children' => [$child]];
        }

        return $this->leaf($node, $event, $subject);
    }

    private function leaf(array $leaf, AutomationEvent $event, ?Model $subject): array
    {
        $field = $leaf['field'] ?? null;
        $op    = $leaf['op'] ?? null;

        if ($field === null || $op === null) {
            throw new InvalidArgumentException(
                'A condition needs a field and an operator, or one of all / any / not.'
            );
        }

        if (! in_array($op, self::OPERATORS, strict: true)) {
            throw new InvalidArgumentException(
                "Unknown condition operator '{$op}'. Operators are fixed in code — "
                .'they are platform invariants, not tenant configuration (§2.1).'
            );
        }

        $expected = $leaf['value'] ?? null;

        $payload = $event->payload ?? [];
        $before  = $payload['before'][$field] ?? null;
        $after   = $this->valueOf($field, $payload, $subject);

        $matched = match ($op) {
            'eq'           => $this->loose($after) === $this->loose($expected),
            'neq'          => $this->loose($after) !== $this->loose($expected),
            'in'           => in_array($this->loose($after), array_map([$this, 'loose'], (array) $expected), strict: true),
            'not_in'       => ! in_array($this->loose($after), array_map([$this, 'loose'], (array) $expected), strict: true),
            'gt'           => $this->numeric($after) > $this->numeric($expected),
            'gte'          => $this->numeric($after) >= $this->numeric($expected),
            'lt'           => $this->numeric($after) < $this->numeric($expected),
            'lte'          => $this->numeric($after) <= $this->numeric($expected),
            'contains'     => str_contains(strtolower((string) $after), strtolower((string) $expected)),
            'not_contains' => ! str_contains(strtolower((string) $after), strtolower((string) $expected)),
            'is_empty'     => blank($after),
            'is_not_empty' => filled($after),

            // The field-change operators only mean anything when the event
            // carried a before — an update. On a create there is nothing to have
            // changed FROM, and answering true would fire "when status changes to
            // completed" on every newly created completed job.
            'changed'      => array_key_exists($field, $payload['before'] ?? [])
                              && $this->loose($before) !== $this->loose($after),
            'changed_to'   => array_key_exists($field, $payload['before'] ?? [])
                              && $this->loose($before) !== $this->loose($after)
                              && $this->loose($after) === $this->loose($expected),
            'changed_from' => array_key_exists($field, $payload['before'] ?? [])
                              && $this->loose($before) !== $this->loose($after)
                              && $this->loose($before) === $this->loose($expected),
        };

        return [
            'type'    => 'condition',
            'field'   => $field,
            'op'      => $op,
            'expected' => $expected,
            'actual'  => $after,
            'matched' => $matched,

            /*
             * WAS THE FIELD THERE AT ALL - which is not the same question as
             * what its value was, and the run log could not tell them apart.
             *
             * A condition naming a field the event does not carry reads null and
             * fails, and so does a condition whose value simply did not match.
             * Both printed "the value at the time was nothing", so the two most
             * different problems a rule can have looked identical to the person
             * reading it.
             *
             * The cost, measured on one real rule: five versions over two weeks,
             * every one skipping in silence. v1 named `from` and `Status` when
             * the payload carries `from_address` and `subject`. v2 and v3 named
             * `subject` for a word that is in the body. v4 named `message_id`,
             * which exists and can never contain it. Four of those five were
             * this question, and nothing on the screen asked it.
             */
            'carried' => $this->carries($field, $payload, $subject),
        ];
    }

    /**
     * Where a condition's field is read from, in order.
     *
     * ── Why the middle arm exists ────────────────────────────────
     *
     * It used to be two arms: `payload['after']`, else the subject model. That
     * silently broke every trigger whose payload is FLAT — keys at the top
     * level rather than nested under `after`. `before`/`after` is the shape of
     * an UPDATE, and a great many events are not updates: an email arriving, a
     * call ending, an SMS, a punch, an inspection being submitted. Those emit
     * what happened, because there is no previous version of anything for a
     * `before` to describe.
     *
     * For all of them the field fell through to the subject model — which is
     * the Mailbox, or the PhoneNumber, or the User — and asked it for `subject`
     * or `from_address` or `duration`, which it has never heard of. Every such
     * condition evaluated to null, so the rule could not match, and the run log
     * said "the value at the time was nothing" once per attempt, forever. The
     * rule looked correct, the trace looked like bad data, and nothing was red:
     * 23 triggers across 8 modules were affected, found from a customer's
     * `email.received` rule that had skipped every run since it was written.
     *
     * Fixing it here rather than in the 27 call sites is deliberate. The
     * alternative was asking eight modules to re-nest payloads under `after`
     * to satisfy a convention no test enforces and nothing announces — and a
     * ninth module written next month would get it wrong again. The evaluator
     * is the one place that has to understand both shapes.
     *
     * `array_key_exists` throughout, not `??`: a payload that explicitly
     * carries null means null, and must not fall through to the model and read
     * a stale value that the event did not describe.
     */
    /**
     * Whether this event carried the field, rather than what it held.
     *
     * The same three arms as {@see valueOf} and it must stay that way: if a
     * value can be READ from somewhere, the field was carried, and a fourth arm
     * added to one and not the other would make the run log say a field is
     * missing while the condition reads it.
     *
     * The subject arm asks the attribute bag rather than `getAttribute`, which
     * answers for accessors too. A rule naming a computed property is a
     * different conversation; here the question is whether the RECORD holds it.
     */
    private function carries(string $field, array $payload, ?Model $subject): bool
    {
        return array_key_exists($field, $payload['after'] ?? [])
            || array_key_exists($field, $payload)
            || ($subject !== null && array_key_exists($field, $subject->getAttributes()));
    }

    private function valueOf(string $field, array $payload, ?Model $subject): mixed
    {
        // Prefer the event's own after-value: the subject may have been changed
        // again between the event being written and this running, and the rule
        // was written about what happened, not about what is true now.
        if (array_key_exists($field, $payload['after'] ?? [])) {
            return $payload['after'][$field];
        }

        // A flat payload — what a non-update event carries. Same argument as
        // above for preferring it to the model.
        if (array_key_exists($field, $payload)) {
            return $payload[$field];
        }

        return $subject?->getAttribute($field);
    }

    /**
     * Compare as strings.
     *
     * JSON round-trips turn 5 into "5" and true into 1 depending on which side
     * of the wire it came from. A rule reading `status eq completed` should not
     * depend on that, and strict comparison across a JSON boundary is a source
     * of rules that silently never match.
     */
    private function loose(mixed $value): string|null
    {
        if ($value === null) {
            return null;
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        // A picker sends "true"/"false" as text; they mean the boolean (office_open, 2026-10-20).
        if ($value === 'true' || $value === 'false') {
            return $value === 'true' ? '1' : '0';
        }

        if (is_array($value)) {
            return json_encode($value);
        }

        return (string) $value;
    }

    private function numeric(mixed $value): float
    {
        return is_numeric($value) ? (float) $value : 0.0;
    }
}
