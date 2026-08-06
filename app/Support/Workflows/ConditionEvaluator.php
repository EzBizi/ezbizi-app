<?php

namespace App\Support\Workflows;

use App\Models\WorkflowEvent;
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
    public function evaluate(?array $conditions, WorkflowEvent $event, ?Model $subject): array
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

    private function node(array $node, WorkflowEvent $event, ?Model $subject): array
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

    private function leaf(array $leaf, WorkflowEvent $event, ?Model $subject): array
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
        // Prefer the event's own after-value: the subject may have been changed
        // again between the event being written and this running, and the rule
        // was written about what happened, not about what is true now.
        $after   = array_key_exists($field, $payload['after'] ?? [])
            ? $payload['after'][$field]
            : $subject?->getAttribute($field);

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
        ];
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
