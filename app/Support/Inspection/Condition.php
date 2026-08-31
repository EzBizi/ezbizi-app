<?php

namespace App\Support\Inspection;

use Illuminate\Validation\ValidationException;

/**
 * Whether a field or a screen is asked at all.
 *
 * ── Not the same thing as `required_if`, which stays ─────────────
 *
 * `required_if` answers "must this be answered". This answers "is this asked".
 * A masonry inspection does not make the wood-stove questions optional — it
 * does not ask them. Both exist, and a field can carry both.
 *
 * ── The shape ────────────────────────────────────────────────────
 *
 *     {"field": "system_type", "op": "eq",     "value":  "masonry"}
 *     {"field": "system_type", "op": "in",     "values": ["masonry", "wood"]}
 *     {"op": "and", "conditions": [ … ]}
 *
 * `eq`, `ne`, `in`, `not_in`, and `and` / `or` over nested conditions. That is
 * the whole language and it is meant to stay that way: the moment it grows
 * arithmetic or free expressions it becomes something that has to be evaluated
 * identically on a phone and on a server, and the two will disagree.
 *
 * ── Two answers that decide what a fresh run looks like ──────────
 *
 * An ABSENT condition shows the field. Every field written before this class
 * has none, so the default is what they already do.
 *
 * An UNANSWERED field fails `eq`/`in` and passes `ne`/`not_in`. That is the
 * state a run opens in — nothing answered — and it has to be right or the form
 * opens almost blank and fills itself in as the technician types. "Not
 * electric" is true of a system nobody has named yet.
 *
 * ── Depth is capped, and that is what makes it safe ──────────────
 *
 * The structure arrives in a request body, so an uncapped tree is a stack
 * overflow somebody can post. Five levels is more than the A1 inspection uses
 * anywhere and more than a Builder could sensibly draw.
 */
final class Condition
{
    public const OPS = ['eq', 'ne', 'in', 'not_in'];
    public const COMBINERS = ['and', 'or'];

    private const MAX_DEPTH = 5;

    /**
     * Is this field or screen shown, given what has been answered so far?
     *
     * @param  mixed  $condition  a condition, or null/[] for "always"
     * @param  array<string, mixed>  $answers
     */
    public static function passes(mixed $condition, array $answers): bool
    {
        if (! is_array($condition) || $condition === []) {
            return true;
        }

        $op = (string) ($condition['op'] ?? '');

        if (in_array($op, self::COMBINERS, true)) {
            $children = is_array($condition['conditions'] ?? null)
                ? $condition['conditions']
                : [];

            // An empty `and` is true and an empty `or` is false, which is what
            // those words mean over an empty set. A Builder should never write
            // one, but a half-built condition should not decide the form.
            if ($op === 'and') {
                foreach ($children as $child) {
                    if (! self::passes($child, $answers)) {
                        return false;
                    }
                }

                return true;
            }

            foreach ($children as $child) {
                if (self::passes($child, $answers)) {
                    return true;
                }
            }

            return false;
        }

        $field = (string) ($condition['field'] ?? '');
        if ($field === '') {
            // A condition that names nothing cannot be evaluated, and hiding
            // the field would silently remove a question. Show it.
            return true;
        }

        $answer = $answers[$field] ?? null;

        $wanted = match ($op) {
            'in', 'not_in' => array_map(
                static fn ($v) => self::scalar($v),
                is_array($condition['values'] ?? null) ? $condition['values'] : [],
            ),
            default => [self::scalar($condition['value'] ?? null)],
        };

        $matched = self::matches($answer, $wanted);

        return match ($op) {
            'eq', 'in' => $matched,
            'ne', 'not_in' => ! $matched,
            // An operator this build does not know shows the field rather than
            // hiding it. A newer server's condition should leave the question
            // answerable, not make it vanish from a form somebody is filling in.
            default => true,
        };
    }

    /**
     * Does the answer match any of the wanted values?
     *
     * A `choice` with `multiple` answers as a LIST, and comparing a list to a
     * string is never equal — so without this every condition on a multi-select
     * would be silently false forever, which reads as "that branch is broken"
     * rather than as a bug in here.
     *
     * @param  list<string>  $wanted
     */
    private static function matches(mixed $answer, array $wanted): bool
    {
        if ($answer === null || $answer === '' || $answer === []) {
            return false;
        }

        $held = is_array($answer)
            ? array_map(static fn ($v) => self::scalar($v), $answer)
            : [self::scalar($answer)];

        return array_intersect($held, $wanted) !== [];
    }

    /** Compared as strings, because `2` from a form and `"2"` from JSON are one answer. */
    private static function scalar(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        return is_scalar($value) ? (string) $value : '';
    }

    /**
     * Check a condition on the way in, and hand back its normalised form.
     *
     * Refused at the Builder boundary rather than tolerated at runtime: a
     * condition nobody validated is one that silently shows or hides the wrong
     * question, and the technician has no way to know.
     *
     * @param  string  $at  the field path to hang an error on
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    public static function validate(mixed $condition, string $at, int $depth = 0): array
    {
        if (! is_array($condition) || $condition === []) {
            return [];
        }

        if ($depth >= self::MAX_DEPTH) {
            throw ValidationException::withMessages([
                $at => 'That condition is nested too deeply. Five levels of and/or is the '
                    .'most this can hold, and more than anything readable needs.',
            ]);
        }

        $op = (string) ($condition['op'] ?? '');

        if (in_array($op, self::COMBINERS, true)) {
            $children = is_array($condition['conditions'] ?? null)
                ? array_values($condition['conditions'])
                : [];

            if ($children === []) {
                throw ValidationException::withMessages([
                    $at => "An \"{$op}\" needs at least one condition under it.",
                ]);
            }

            return [
                'op' => $op,
                'conditions' => array_map(
                    static fn ($child) => self::validate($child, $at, $depth + 1),
                    $children,
                ),
            ];
        }

        if (! in_array($op, self::OPS, true)) {
            throw ValidationException::withMessages([
                $at => sprintf(
                    "'%s' is not something a condition can do. Use one of: %s, or and/or.",
                    $op === '' ? '(empty)' : $op,
                    implode(', ', self::OPS),
                ),
            ]);
        }

        $field = trim((string) ($condition['field'] ?? ''));

        if ($field === '') {
            throw ValidationException::withMessages([
                $at => 'A condition has to say which field it looks at.',
            ]);
        }

        if ($op === 'in' || $op === 'not_in') {
            $values = is_array($condition['values'] ?? null)
                ? array_values(array_map(static fn ($v) => self::scalar($v), $condition['values']))
                : [];

            if ($values === []) {
                throw ValidationException::withMessages([
                    $at => "An \"{$op}\" needs at least one value to look for.",
                ]);
            }

            return ['field' => $field, 'op' => $op, 'values' => $values];
        }

        return ['field' => $field, 'op' => $op, 'value' => self::scalar($condition['value'] ?? null)];
    }

    /**
     * Every field key this condition reads.
     *
     * The Builder uses it to refuse a condition pointing at a field that does
     * not exist — the failure that otherwise shows up as a screen nobody can
     * ever reach, with nothing on any screen to explain why.
     *
     * @return list<string>
     */
    public static function fieldsNamed(mixed $condition): array
    {
        if (! is_array($condition) || $condition === []) {
            return [];
        }

        if (in_array((string) ($condition['op'] ?? ''), self::COMBINERS, true)) {
            $keys = [];
            foreach ((is_array($condition['conditions'] ?? null) ? $condition['conditions'] : []) as $child) {
                $keys = [...$keys, ...self::fieldsNamed($child)];
            }

            return array_values(array_unique($keys));
        }

        $field = trim((string) ($condition['field'] ?? ''));

        return $field === '' ? [] : [$field];
    }
}
